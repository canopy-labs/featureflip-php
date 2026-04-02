<?php

declare(strict_types=1);

namespace Featureflip;

use Featureflip\DataSource\Poller;
use Featureflip\Evaluation\Evaluator;
use Featureflip\Events\{Event, EventProcessor};
use Featureflip\Http\HttpClient;
use Featureflip\Store\FlagStore;

final class Client
{
    private readonly Evaluator $evaluator;
    private readonly ?FlagStore $store;
    private readonly ?EventProcessor $eventProcessor;
    private readonly ?Poller $poller;

    /** @var array<string, mixed>|null For test mode */
    private readonly ?array $testFlags;

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

        // Fetch flags if cache is expired
        if ($store->isExpired()) {
            try {
                $poller->fetch();
            } catch (\Throwable) {
                // Gracefully degrade — use stale cache or defaults
            }
        }

        $client = new self($store, $eventProcessor, $poller, null);

        // Register shutdown function to flush events async
        register_shutdown_function(function () use ($client): void {
            if (function_exists('fastcgi_finish_request')) {
                fastcgi_finish_request();
            }
            $client->flush();
        });

        return $client;
    }

    /**
     * @param array<string, mixed> $flags
     */
    public static function forTesting(array $flags): self
    {
        return new self(null, null, null, $flags);
    }

    /**
     * @param array<string, mixed> $context
     */
    public function boolVariation(string $key, array $context, bool $default): bool
    {
        $detail = $this->evaluateFlag($key, $context, $default);
        return is_bool($detail->value) ? $detail->value : $default;
    }

    /**
     * @param array<string, mixed> $context
     */
    public function stringVariation(string $key, array $context, string $default): string
    {
        $detail = $this->evaluateFlag($key, $context, $default);
        return is_string($detail->value) ? $detail->value : $default;
    }

    /**
     * @param array<string, mixed> $context
     */
    public function numberVariation(string $key, array $context, int|float $default): int|float
    {
        $detail = $this->evaluateFlag($key, $context, $default);
        return is_int($detail->value) || is_float($detail->value) ? $detail->value : $default;
    }

    /**
     * @param array<string, mixed> $context
     * @param array<string, mixed> $default
     * @return array<string, mixed>
     */
    public function jsonVariation(string $key, array $context, array $default): array
    {
        $detail = $this->evaluateFlag($key, $context, $default);
        return is_array($detail->value) ? $detail->value : $default;
    }

    /**
     * @param array<string, mixed> $context
     */
    public function variationDetail(string $key, array $context, mixed $default): EvaluationDetail
    {
        return $this->evaluateFlag($key, $context, $default);
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

    public function close(): void
    {
        $this->flush();
    }

    /**
     * @param array<string, mixed> $context
     */
    private function evaluateFlag(string $key, array $context, mixed $default): EvaluationDetail
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
}
