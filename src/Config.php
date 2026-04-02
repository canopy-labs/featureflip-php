<?php

declare(strict_types=1);

namespace Featureflip;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\SimpleCache\CacheInterface;

final class Config
{
    public function __construct(
        public readonly string $baseUrl = 'https://eval.featureflip.io',
        public readonly int $pollInterval = 30,
        public readonly int $flushInterval = 30,
        public readonly int $flushBatchSize = 100,
        public readonly int $initTimeout = 10,
        public readonly ?CacheInterface $cache = null,
        public readonly ?ClientInterface $httpClient = null,
        public readonly ?RequestFactoryInterface $requestFactory = null,
        public readonly ?StreamFactoryInterface $streamFactory = null,
    ) {}
}
