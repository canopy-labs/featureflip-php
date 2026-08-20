<?php

declare(strict_types=1);

namespace Featureflip\Http;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Featureflip\Logging\ErrorLogLogger;
use Psr\Log\LoggerInterface;

class HttpClient
{
    /**
     * Reported in the User-Agent. composer.json carries no version (Packagist
     * reads git tags), so this is maintained by hand and held equal to
     * CHANGELOG.md's newest heading by tools/check-sdk-versions (CI workflow
     * sdk-version-consistency.yml). Bump both together.
     */
    private const VERSION = '3.0.1';

    private LoggerInterface $logger;

    /** Message from the most recent failed post, for the caller to report. */
    private ?string $lastError = null;

    public function __construct(
        private readonly ClientInterface $client,
        private readonly RequestFactoryInterface $requestFactory,
        private readonly StreamFactoryInterface $streamFactory,
        private readonly string $sdkKey,
        private readonly string $baseUrl,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new ErrorLogLogger();
    }

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
     * The message from the most recent failed {@see post()}, if any.
     */
    public function lastError(): ?string
    {
        return $this->lastError;
    }

    /**
     * Best-effort delivery. Returns false when the payload could not be sent,
     * so the caller can report once per flush rather than once per batch.
     *
     * @param array<string, mixed> $body
     */
    public function post(string $path, array $body): bool
    {
        try {
            $json = json_encode($body, JSON_THROW_ON_ERROR);
            $request = $this->requestFactory->createRequest('POST', $this->baseUrl . $path)
                ->withHeader('Authorization', $this->sdkKey)
                ->withHeader('User-Agent', 'featureflip-php/' . self::VERSION)
                ->withHeader('Content-Type', 'application/json')
                ->withBody($this->streamFactory->createStream($json));

            $this->client->sendRequest($request);

            return true;
        } catch (\Throwable $e) {
            // Event delivery stays best-effort — analytics must never break an
            // evaluation — but it is no longer silent. Dropped events used to
            // leave no trace at all, so a customer's analytics could be wholly
            // absent with nothing to explain it (#2258). Reporting is left to
            // the caller so one failed flush is one log line, however many
            // batches it was chunked into.
            $this->lastError = $e->getMessage();

            return false;
        }
    }
}
