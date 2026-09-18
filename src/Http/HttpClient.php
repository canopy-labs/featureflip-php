<?php

declare(strict_types=1);

namespace Featureflip\Http;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * The SDK's own transport against the evaluation API: config fetches for the
 * poller, analytics batches for the event processor. Constructed only by
 * {@see \Featureflip\SharedFeatureflipCore}, which owns the credential and the
 * redacting logger.
 *
 * @internal Not part of the public API. The 3.x changelog described this
 *           class's surface without that caveat, so the marker is new even
 *           though the status is not: nothing outside this package was ever
 *           meant to construct it, and lastFailure() already returns the
 *           @internal PostFailure. Use {@see \Featureflip\FeatureflipClient}.
 */
class HttpClient
{
    /**
     * Reported in the User-Agent. composer.json carries no version (Packagist
     * reads git tags), so this is maintained by hand and held equal to
     * CHANGELOG.md's newest heading by tools/check-sdk-versions (CI workflow
     * sdk-version-consistency.yml). Bump both together.
     */
    private const VERSION = '3.4.0';

    /** Why the most recent post failed, for the caller to classify and report. */
    private ?PostFailure $lastFailure = null;

    /**
     * Deliberately takes no logger, unlike its sibling collaborators. Every
     * failure this class can produce is already reported by whoever called it,
     * and reporting here as well would duplicate the line rather than add one:
     * post() records the reason on lastFailure() and returns false so the
     * caller reports once per FLUSH rather than once per batch (#2258), and
     * get() throws for SharedFeatureflipCore::refreshIfStale() to catch and
     * warn about.
     *
     * It did accept a PSR-3 logger until 3.3.0, and stored it without ever
     * reading it — so a caller who passed one got silence and no way to tell
     * that from a quiet HTTP layer. PHP ignores surplus positional arguments
     * to userland functions, so dropping the parameter only affects a caller
     * passing it by name.
     */
    public function __construct(
        private readonly ClientInterface $client,
        private readonly RequestFactoryInterface $requestFactory,
        private readonly StreamFactoryInterface $streamFactory,
        private readonly string $sdkKey,
        private readonly string $baseUrl,
    ) {
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
        return $this->lastFailure?->message;
    }

    /**
     * Why the most recent {@see post()} failed, if it did — the status it came
     * back with, and whether the same body is worth sending again.
     *
     * Read it immediately after a `post()` that returned false: it describes
     * that call and is replaced by the next one.
     */
    public function lastFailure(): ?PostFailure
    {
        return $this->lastFailure;
    }

    /**
     * Best-effort delivery. Returns false when the payload could not be sent,
     * so the caller can report once per flush rather than once per batch;
     * {@see lastFailure()} carries why.
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

            $response = $this->client->sendRequest($request);
            $status = $response->getStatusCode();

            // A PSR-18 client throws only on a TRANSPORT fault — a 4xx or 5xx is
            // an ordinary return value to it. So the catch below never saw one,
            // and every rejected batch was counted as delivered: the reporting
            // added by #2258 could not fire for the 503 the public edge answers
            // this endpoint with, and the events were discarded as sent (#2456).
            if ($status < 200 || $status >= 300) {
                $this->lastFailure = new PostFailure("HTTP {$status} from {$path}", $status);

                return false;
            }

            $this->lastFailure = null;

            return true;
        } catch (\Throwable $e) {
            // Event delivery stays best-effort — analytics must never break an
            // evaluation — but it is no longer silent. Dropped events used to
            // leave no trace at all, so a customer's analytics could be wholly
            // absent with nothing to explain it (#2258). Reporting is left to
            // the caller so one failed flush is one log line, however many
            // batches it was chunked into.
            //
            // No status: nothing came back, so the caller treats it as transient.
            $this->lastFailure = new PostFailure($e->getMessage());

            return false;
        }
    }
}
