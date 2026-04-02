<?php

declare(strict_types=1);

namespace Featureflip\Http;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;

class HttpClient
{
    private const VERSION = '0.1.0';

    public function __construct(
        private readonly ClientInterface $client,
        private readonly RequestFactoryInterface $requestFactory,
        private readonly StreamFactoryInterface $streamFactory,
        private readonly string $sdkKey,
        private readonly string $baseUrl,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function get(string $path): array
    {
        $request = $this->requestFactory->createRequest('GET', $this->baseUrl . $path)
            ->withHeader('Authorization', $this->sdkKey)
            ->withHeader('User-Agent', 'featureflip-php/' . self::VERSION)
            ->withHeader('Accept', 'application/json');

        $response = $this->client->sendRequest($request);

        if ($response->getStatusCode() >= 400) {
            throw new \RuntimeException("HTTP {$response->getStatusCode()} from {$path}");
        }

        /** @var array<string, mixed> */
        return json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * @param array<string, mixed> $body
     */
    public function post(string $path, array $body): void
    {
        try {
            $json = json_encode($body, JSON_THROW_ON_ERROR);
            $request = $this->requestFactory->createRequest('POST', $this->baseUrl . $path)
                ->withHeader('Authorization', $this->sdkKey)
                ->withHeader('User-Agent', 'featureflip-php/' . self::VERSION)
                ->withHeader('Content-Type', 'application/json')
                ->withBody($this->streamFactory->createStream($json));

            $this->client->sendRequest($request);
        } catch (\Throwable) {
            // Best-effort for events, don't throw
        }
    }
}
