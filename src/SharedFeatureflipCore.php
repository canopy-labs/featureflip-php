<?php

declare(strict_types=1);

namespace Featureflip;

use Featureflip\DataSource\Poller;
use Featureflip\Evaluation\Evaluator;
use Featureflip\Events\{Event, EventProcessor};
use Featureflip\Http\HttpClient;
use Featureflip\Logging\ErrorLogLogger;
use Featureflip\Logging\RedactingLogger;
use Featureflip\Store\FlagStore;
use Psr\Log\LoggerInterface;

/**
 * @internal Not part of the public API — shared resource core with refcounted lifecycle.
 */
final class SharedFeatureflipCore
{
    private Evaluator $evaluator;
    private ?FlagStore $store;
    private ?EventProcessor $eventProcessor;
    private ?Poller $poller;
    private LoggerInterface $logger;

    /**
     * Earliest time this process should look at the shared backoff marker
     * again. In-memory on purpose — see refreshIfStale().
     */
    private ?int $nextRefreshCheckAt = null;

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
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new ErrorLogLogger();
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

        // Everything the SDK logs goes through the redactor, including the
        // default error-log sink: failure messages quote exceptions raised by
        // the caller's own HTTP client, which may have the Authorization header
        // in them (#2266).
        $logger = new RedactingLogger($config->logger ?? new ErrorLogLogger(), $sdkKey);

        $httpClient = new HttpClient(
            $config->httpClient,
            $config->requestFactory,
            $streamFactory,
            $sdkKey,
            rtrim($config->baseUrl, '/'),
            $logger,
        );

        $store = new FlagStore(
            $config->cache,
            md5($sdkKey),
            $config->pollInterval,
            $logger,
        );

        $poller = new Poller($httpClient, $store, $logger);
        $eventProcessor = new EventProcessor(
            $httpClient,
            $config->flushBatchSize,
            $logger,
            $config->flushInterval,
        );

        $instance = new self($store, $eventProcessor, $poller, null, $config->inspectors, $logger);

        $instance->refreshIfStale();

        // Register shutdown function to flush events async
        register_shutdown_function($instance->shutdown(...));

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

    /**
     * Fetch a new configuration when the held one is due a refresh.
     *
     * Called at construction and again from the evaluation path. Under PHP-FPM
     * the two are the same thing — every request builds a core — but a
     * persistent worker (Octane, RoadRunner, FrankenPHP, Swoole) builds one at
     * boot and keeps it for thousands of requests, so a construction-only check
     * meant flags froze at boot until the process restarted (#2260).
     *
     * Cheap on the hot path: when the configuration is fresh this is one
     * integer comparison. When it is stale exactly one caller pays the fetch
     * and the rest read the refreshed store; when the API is unreachable the
     * backoff caps attempts at one per poll interval across every worker,
     * rather than one per evaluation.
     */
    private function refreshIfStale(): void
    {
        if ($this->store === null || $this->poller === null) {
            return;
        }

        // Fresh configuration: one integer comparison against an in-memory
        // timestamp, and nothing else. This is the overwhelmingly common case
        // and it must never touch the cache.
        if (!$this->store->isExpired()) {
            return;
        }

        // Stale, and a recent failure is still backing off. The marker lives in
        // the shared cache so the backoff holds across workers — but reading it
        // is a round-trip to Redis or the filesystem, and a stale store stays
        // stale for the whole outage, so consulting it per evaluation would
        // turn every flag check into a network call for as long as the
        // evaluation API is down. Remember the answer in-process and re-ask at
        // most once a second.
        $now = time();
        if ($this->nextRefreshCheckAt !== null && $now < $this->nextRefreshCheckAt) {
            return;
        }

        if ($this->store->isRefreshBackedOff()) {
            $this->nextRefreshCheckAt = $now + 1;

            return;
        }

        // Claim the attempt BEFORE going near the network. The fetch runs
        // inline on the caller's thread, and the caller's own PSR-18 client may
        // evaluate a flag itself — a Guzzle middleware gating a retry, say. The
        // re-entrant call would otherwise find the same stale store and an
        // unclaimed slot, and recurse until the process dies.
        $this->nextRefreshCheckAt = $now + 1;

        try {
            $this->poller->fetch();

            // Release the claim only if a fresh snapshot actually landed. The
            // poller declines to publish a response that carries no usable
            // flags (#2258), which leaves the store stale — releasing here
            // would make the next evaluation retry immediately, and the one
            // after that, for as long as the evaluation API kept answering.
            if (!$this->store->isExpired()) {
                $this->nextRefreshCheckAt = null;
            }
        } catch (\Throwable $e) {
            // Degrade to the last known good configuration, which outlives the
            // poll interval. Crucially this is REPORTED: the old bare
            // `catch (\Throwable)` bound no variable, so it could not log even
            // in principle, and a wrong SDK key or an unreachable API produced
            // total silence while every flag quietly served its caller
            // default (#2258).
            $this->store->recordFailedRefresh();
            $this->warn(
                'flag configuration fetch failed: ' . $e->getMessage()
                . ($this->store->isEmpty()
                    ? ' - no cached configuration is available, so flags will serve their caller defaults'
                    : ' - serving the last known good configuration'),
            );
        }
    }

    /**
     * Report without ever failing the caller.
     *
     * This runs on the evaluation path now, and a PSR-3 logger is the host
     * application's code: Monolog throws `UnexpectedValueException` when its
     * stream handler cannot open its file — a full disk, a rotated directory,
     * a permissions change. Letting that out of boolVariation() would put the
     * SDK straight back into the failure mode #1990 removed.
     */
    private function warn(string $message): void
    {
        try {
            $this->logger->warning($message);
        } catch (\Throwable) {
            // Nowhere left to report to.
        }
    }

    /**
     * Whether a flag configuration has been loaded.
     *
     * "Loaded", not "fresh": a snapshot retained through an evaluation-API
     * outage is still a configuration, which is exactly what #2258 exists to
     * provide. A test stub counts as loaded — it has values to serve — which
     * matches the python SDK's explicit carve-out.
     */
    public function isInitialized(): bool
    {
        if ($this->testFlags !== null) {
            return true;
        }

        return $this->store?->isLoaded() ?? false;
    }

    /**
     * Refresh the configuration now if it is due one.
     *
     * The evaluation path does this by itself, so calling this is optional. It
     * exists for persistent workers that would rather the fetch happened
     * *between* requests than inside one — an Octane tick listener or a
     * RoadRunner worker loop — so no user's request ever pays for it.
     *
     * Honours the poll interval and the failure backoff, exactly as the
     * automatic path does, which makes it safe to call on a tight tick.
     */
    public function refresh(): void
    {
        $this->refreshIfStale();
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

        // Return the response before shipping whatever events are still
        // queued, so the user never waits on analytics. Deliberately *after*
        // the guard: it used to run unconditionally on every registered
        // shutdown handler, so a client that had already been closed still
        // ended the host application's request as a side effect of the SDK
        // merely having been constructed (#2260).
        if ($this->eventProcessor?->queueSize() > 0 && function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        }

        // close(), not flush(): this is the last attempt this process will make,
        // so a batch the endpoint rejects must be let go rather than re-queued
        // for a flush that will never come (#2456).
        $this->eventProcessor?->close();
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

        $this->refreshIfStale();

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
