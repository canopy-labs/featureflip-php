<?php

declare(strict_types=1);

namespace Featureflip;

use Featureflip\DataSource\Poller;
use Featureflip\Evaluation\Evaluator;
use Featureflip\Events\{Event, EventProcessor};
use Featureflip\Http\HttpClient;
use Featureflip\Store\FlagStore;

/**
 * @internal Not part of the public API — shared resource core with refcounted lifecycle.
 */
final class SharedFeatureflipCore
{
    private Evaluator $evaluator;
    private ?FlagStore $store;
    private ?EventProcessor $eventProcessor;
    private ?Poller $poller;

    /** @var array<string, mixed>|null For test mode */
    private ?array $testFlags;

    /** @var list<callable> Registered evaluation inspectors (already filtered to callables). */
    private array $inspectors;

    private int $refCount = 1;
    private bool $isShutDown = false;

    /**
     * @param array<mixed> $inspectors Raw inspector list from Config; non-callable
     *                                 entries are dropped here so a bad entry can
     *                                 never blow up on the evaluation hot path.
     */
    private function __construct(
        ?FlagStore $store,
        ?EventProcessor $eventProcessor,
        ?Poller $poller,
        ?array $testFlags,
        array $inspectors = [],
    ) {
        $this->evaluator = new Evaluator();
        $this->store = $store;
        $this->eventProcessor = $eventProcessor;
        $this->poller = $poller;
        $this->testFlags = $testFlags;
        $this->inspectors = array_values(
            array_filter($inspectors, static fn (mixed $i): bool => is_callable($i)),
        );
    }

    public static function create(string $sdkKey, Config $config): self
    {
        if ($config->httpClient === null || $config->requestFactory === null || $config->cache === null) {
            throw new \InvalidArgumentException('httpClient, requestFactory, and cache are required');
        }

        $streamFactory = $config->streamFactory;
        if ($streamFactory === null) {
            throw new \InvalidArgumentException('streamFactory is required');
        }

        $httpClient = new HttpClient(
            $config->httpClient,
            $config->requestFactory,
            $streamFactory,
            $sdkKey,
            rtrim($config->baseUrl, '/'),
        );

        $store = new FlagStore(
            $config->cache,
            md5($sdkKey),
            $config->pollInterval,
        );

        $poller = new Poller($httpClient, $store);
        $eventProcessor = new EventProcessor($httpClient, $config->flushBatchSize);

        $instance = new self($store, $eventProcessor, $poller, null, $config->inspectors);

        // Fetch flags if cache is expired
        if ($store->isExpired()) {
            try {
                $poller->fetch();
            } catch (\Throwable) {
                // Gracefully degrade — use stale cache or defaults
            }
        }

        // Register shutdown function to flush events async
        register_shutdown_function(function () use ($instance): void {
            if (function_exists('fastcgi_finish_request')) {
                fastcgi_finish_request();
            }
            $instance->shutdown();
        });

        return $instance;
    }

    /**
     * @param array<string, mixed> $flags
     * @param array<mixed>         $inspectors Raw inspector list — threaded through
     *                                         so a test-stub client notifies
     *                                         inspectors exactly like a live one.
     */
    public static function createForTesting(array $flags, array $inspectors = []): self
    {
        return new self(null, null, null, $flags, $inspectors);
    }

    public function acquire(): bool
    {
        if ($this->refCount <= 0) {
            return false;
        }
        $this->refCount++;
        return true;
    }

    /**
     * Decrement refcount. Returns true if the core should be disposed (refcount reached zero).
     */
    public function release(): bool
    {
        $this->refCount--;
        return $this->refCount <= 0;
    }

    public function shutdown(): void
    {
        if ($this->isShutDown) {
            return;
        }
        $this->isShutDown = true;
        $this->flush();
    }

    /**
     * The single evaluation choke point — every boolVariation/stringVariation/
     * numberVariation/jsonVariation/variationDetail call funnels through here,
     * on every exit path (test-mode, flag-not-found, and the normal evaluator
     * result — which itself carries FALLTHROUGH / RULE_MATCH / FLAG_DISABLED /
     * PREREQUISITE_FAILED / ERROR).
     *
     * Inspectors are deliberately NOT notified here: the typed accessors on
     * FeatureflipClient apply a second type guard after this returns
     * (boolVariation() falls back to its default when the served value isn't a
     * bool, etc.), so only the accessor knows the value the caller actually
     * receives. Each accessor calls notifyInspectors() itself, exactly once,
     * with its post-guard value. An unexpected exception thrown by the
     * evaluator is caught here and degraded to the caller's default with reason
     * ERROR — the SDK fails safe rather than letting it reach the host, and the
     * accessor's inspector notification still fires (as an ERROR event).
     *
     * @param array<string, mixed> $context
     */
    public function evaluateFlag(string $key, array $context, mixed $default): EvaluationDetail
    {
        // Test mode
        if ($this->testFlags !== null) {
            return array_key_exists($key, $this->testFlags)
                ? new EvaluationDetail($this->testFlags[$key], 'FALLTHROUGH')
                : new EvaluationDetail($default, 'FLAG_NOT_FOUND');
        }

        $flag = $this->store?->getFlag($key);
        $segments = $this->store?->getSegments() ?? [];
        $allFlags = $this->store?->getFlags() ?? [];

        try {
            $detail = $this->evaluator->evaluate($flag, $context, $segments, $allFlags);
        } catch (\Throwable $e) {
            // Fail safe: an unexpected evaluator error degrades to the caller's
            // default with reason ERROR rather than propagating into the host
            // application — a feature flag SDK failing open is the failure mode
            // flags exist to avoid, and every other server SDK degrades here.
            // The error is logged (not swallowed silently) and the ERROR-reason
            // inspector event downstream preserves observability.
            error_log('[featureflip] flag evaluation threw for "' . $key . '": ' . $e->getMessage());
            $detail = new EvaluationDetail($default, 'ERROR');
        }

        // Track evaluation event
        $this->eventProcessor?->push(Event::evaluation($key, $context, $detail->variationKey));

        if ($detail->reason === 'FLAG_NOT_FOUND') {
            return new EvaluationDetail($default, $detail->reason, $detail->ruleId, $detail->variationKey);
        }

        // Malformed config: the evaluator selected a variation key the flag does
        // not define (e.g. a fallthrough/rule naming a since-deleted variation).
        // Report ERROR, mirroring the engine's ServeVariation + the C#/Java SDKs
        // (#1989). A variation that genuinely exists with a null value is NOT
        // this case — hence the key lookup rather than a null-value check.
        if (
            $flag !== null
            && $detail->variationKey !== null
            && $detail->variationKey !== ''
            && $flag->getVariation($detail->variationKey) === null
        ) {
            return new EvaluationDetail(
                $detail->value,
                'ERROR',
                $detail->ruleId,
                $detail->variationKey,
                $detail->prerequisiteKey,
            );
        }

        return $detail;
    }

    /**
     * Fire the registered evaluation inspectors for one completed variation
     * call. A throwing inspector is isolated: it neither changes the returned
     * value nor stops the remaining inspectors — the failure is logged and
     * swallowed.
     *
     * Called by FeatureflipClient's public accessors (never by evaluateFlag),
     * exactly once per public variation call, after the accessor's type guard
     * has produced the value the caller receives.
     *
     * @internal
     *
     * @param array<string, mixed> $context
     * @param mixed                $value   The post-type-guard value the caller
     *                                      receives — may differ from
     *                                      $detail->value when the served value
     *                                      failed the accessor's type check.
     */
    public function notifyInspectors(string $key, array $context, EvaluationDetail $detail, mixed $value): void
    {
        // Hot-path guard: allocate nothing when nobody is listening.
        if ($this->inspectors === []) {
            return;
        }

        $event = new EvaluationEvent(
            flagKey: $key,
            // PHP arrays are value types, so this is already a copy — a buggy
            // inspector cannot reach back into the caller's array.
            context: $context,
            // Exactly what the caller receives: the accessor's post-type-guard
            // value (so a type-mismatched flag reports the default the caller
            // got, not the served value), or, for variationDetail(), the
            // detail's own value since that accessor applies no guard.
            value: $value,
            variationKey: $detail->variationKey,
            reason: $detail->reason,
            ruleId: $detail->ruleId,
            prerequisiteKey: $detail->prerequisiteKey,
            timestamp: (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.v\Z'),
        );

        foreach ($this->inspectors as $inspector) {
            try {
                $inspector($event);
            } catch (\Throwable $e) {
                error_log('[featureflip] evaluation inspector threw: ' . $e->getMessage());
            }
        }
    }

    /**
     * @param array<string, mixed> $context
     * @param array<string, mixed> $metadata
     */
    public function track(string $eventKey, array $context, array $metadata = []): void
    {
        $this->eventProcessor?->push(Event::custom($eventKey, $context, $metadata));
    }

    /**
     * @param array<string, mixed> $context
     */
    public function identify(array $context): void
    {
        $this->eventProcessor?->push(Event::identify($context));
    }

    public function flush(): void
    {
        $this->eventProcessor?->flush();
    }
}
