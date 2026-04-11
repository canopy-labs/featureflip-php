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

    private int $refCount = 1;
    private bool $isShutDown = false;

    private function __construct(
        ?FlagStore $store,
        ?EventProcessor $eventProcessor,
        ?Poller $poller,
        ?array $testFlags,
    ) {
        $this->evaluator = new Evaluator();
        $this->store = $store;
        $this->eventProcessor = $eventProcessor;
        $this->poller = $poller;
        $this->testFlags = $testFlags;
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

        $instance = new self($store, $eventProcessor, $poller, null);

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
     */
    public static function createForTesting(array $flags): self
    {
        return new self(null, null, null, $flags);
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
     * @param array<string, mixed> $context
     */
    public function evaluateFlag(string $key, array $context, mixed $default): EvaluationDetail
    {
        // Test mode
        if ($this->testFlags !== null) {
            if (array_key_exists($key, $this->testFlags)) {
                return new EvaluationDetail($this->testFlags[$key], 'FALLTHROUGH');
            }
            return new EvaluationDetail($default, 'FLAG_NOT_FOUND');
        }

        $flag = $this->store?->getFlag($key);
        $segments = $this->store?->getSegments() ?? [];

        $detail = $this->evaluator->evaluate($flag, $context, $segments);

        // Track evaluation event
        $this->eventProcessor?->push(Event::evaluation($key, $context, $detail->variationKey));

        if ($detail->reason === 'FLAG_NOT_FOUND') {
            return new EvaluationDetail($default, $detail->reason, $detail->ruleId, $detail->variationKey);
        }

        return $detail;
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
