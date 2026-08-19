<?php

declare(strict_types=1);

namespace Featureflip;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Log\LoggerInterface;
use Psr\SimpleCache\CacheInterface;

final class Config
{
    /**
     * @param array<mixed> $inspectors Evaluation inspectors — callables invoked
     *                                 synchronously with an {@see EvaluationEvent}
     *                                 on every variation call. Honored when the
     *                                 shared core is first created for an SDK key
     *                                 (like every other option). Non-callable
     *                                 entries are dropped at construction.
     * Note there is no timeout option. The SDK evaluates through a PSR-18
     * client you supply, and PSR-18's `sendRequest()` exposes no timeout or
     * cancellation hook — so the SDK cannot bound a call it did not originate.
     * Configure the timeout on the client you inject:
     * `new GuzzleHttp\Client(['timeout' => 5, 'connect_timeout' => 2])`.
     * This matters: the configuration fetch runs inline on the caller's thread.
     *
     * @param ?LoggerInterface $logger  Where the SDK reports config-load
     *                                  failures — an unreachable evaluation
     *                                  API, a rejected SDK key, a flag it had
     *                                  to skip. Defaults to
     *                                  {@see \Featureflip\Logging\ErrorLogLogger},
     *                                  which writes to PHP's error log; pass
     *                                  your application's PSR-3 logger to get
     *                                  these where you actually read them.
     */
    public function __construct(
        public readonly string $baseUrl = 'https://eval.featureflip.io',
        public readonly int $pollInterval = 30,
        public readonly int $flushInterval = 30,
        public readonly int $flushBatchSize = 100,
        public readonly ?CacheInterface $cache = null,
        public readonly ?ClientInterface $httpClient = null,
        public readonly ?RequestFactoryInterface $requestFactory = null,
        public readonly ?StreamFactoryInterface $streamFactory = null,
        public readonly array $inspectors = [],
        public readonly ?LoggerInterface $logger = null,
    ) {}
}
