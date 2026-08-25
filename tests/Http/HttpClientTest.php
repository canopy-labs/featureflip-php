<?php

declare(strict_types=1);

namespace Featureflip\Tests\Http;

use Featureflip\Http\HttpClient;
use GuzzleHttp\Psr7\HttpFactory;
use Psr\Http\Client\{ClientExceptionInterface, ClientInterface};
use Psr\Http\Message\{RequestInterface, ResponseInterface};
use Psr\Log\NullLogger;
use PHPUnit\Framework\TestCase;

/**
 * What `post()` counts as a delivery failure (#2456).
 *
 * PSR-18 clients throw only on a TRANSPORT fault — a 4xx or 5xx is a perfectly
 * ordinary return value. `post()` reported success for anything `sendRequest()`
 * did not throw on, so the production edge answering this endpoint with a 503
 * looked exactly like a delivered batch: no counter, no log line, nothing for
 * the #2258 reporting to fire on.
 */
final class HttpClientTest extends TestCase
{
    public function testASuccessfulPostReportsNoFailure(): void
    {
        $client = $this->client(new StubTransport(200));

        $this->assertTrue($client->post('/v1/sdk/events', ['events' => []]));
        $this->assertNull($client->lastFailure());
    }

    /** Anything 2xx is a success — the events endpoint may answer 202/204. */
    public function testAnyTwoHundredStatusIsASuccess(): void
    {
        foreach ([200, 201, 202, 204] as $status) {
            $client = $this->client(new StubTransport($status));
            $this->assertTrue($client->post('/v1/sdk/events', []), "HTTP {$status} must count as delivered");
        }
    }

    public function testAServerErrorIsReportedAsAFailedPostCarryingItsStatus(): void
    {
        $client = $this->client(new StubTransport(503));

        $this->assertFalse($client->post('/v1/sdk/events', ['events' => []]));
        $failure = $client->lastFailure();
        $this->assertNotNull($failure);
        $this->assertSame(503, $failure->statusCode);
        $this->assertTrue($failure->isRetryable(), 'A 5xx is exactly the case a later flush can get past');
        $this->assertStringContainsString('503', (string) $client->lastError());
    }

    public function testARejectedSdkKeyIsReportedAsPermanent(): void
    {
        foreach ([400, 401, 403, 404] as $status) {
            $client = $this->client(new StubTransport($status));

            $this->assertFalse($client->post('/v1/sdk/events', []));
            $this->assertFalse(
                (bool) $client->lastFailure()?->isRetryable(),
                "HTTP {$status} will fail identically next time and must not be retried",
            );
        }
    }

    public function testRateLimitingIsRetryable(): void
    {
        $client = $this->client(new StubTransport(429));

        $this->assertFalse($client->post('/v1/sdk/events', []));
        $this->assertTrue($client->lastFailure()?->isRetryable());
    }

    public function testATransportFaultIsReportedWithNoStatusAndIsRetryable(): void
    {
        $client = $this->client(new StubTransport(200, new TransportFault('connection reset by peer')));

        $this->assertFalse($client->post('/v1/sdk/events', []));
        $failure = $client->lastFailure();
        $this->assertNotNull($failure);
        $this->assertNull($failure->statusCode, 'No response ever arrived, so there is no status to carry');
        $this->assertTrue($failure->isRetryable());
        $this->assertStringContainsString('connection reset by peer', (string) $client->lastError());
    }

    private function client(StubTransport $transport): HttpClient
    {
        $factory = new HttpFactory();

        return new HttpClient(
            $transport->withFactory($factory),
            $factory,
            $factory,
            'sdk-key',
            'http://eval.test',
            new NullLogger(),
        );
    }
}

/** A PSR-18 client that answers with a fixed status, or refuses to connect. */
final class StubTransport implements ClientInterface
{
    private HttpFactory $factory;

    public function __construct(private readonly int $status, private readonly ?\Throwable $fault = null) {}

    public function withFactory(HttpFactory $factory): self
    {
        $this->factory = $factory;
        return $this;
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        if ($this->fault !== null) {
            throw $this->fault;
        }

        return $this->factory->createResponse($this->status)->withBody($this->factory->createStream('{}'));
    }
}

/** What a PSR-18 client raises when the request never produced a response. */
final class TransportFault extends \RuntimeException implements ClientExceptionInterface {}
