<?php

declare(strict_types=1);

namespace Featureflip\Tests\Events;

use Featureflip\{Config, FeatureflipClient};
use GuzzleHttp\Psr7\HttpFactory;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\{RequestInterface, ResponseInterface};
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Psr16Cache;

/**
 * A PSR-18 evaluation API for tests that need a REAL client: it serves a fixed
 * snapshot on GET and records the body of every POST /v1/sdk/events, as the SDK
 * serialized it. (`forTesting()` bypasses analytics entirely, so it cannot be
 * used to observe what goes on the wire.)
 */
final class CapturingEvalApi implements ClientInterface
{
    /** @var list<array<string, mixed>> */
    public array $events = [];
    private HttpFactory $factory;

    /**
     * @param list<array<string, mixed>> $flags
     * @param list<array<string, mixed>> $segments
     */
    public function __construct(private array $flags, private array $segments = [])
    {
        $this->factory = new HttpFactory();
    }

    public function client(): FeatureflipClient
    {
        return FeatureflipClient::get('capture-key', new Config(
            baseUrl: 'http://eval.test',
            pollInterval: 300,
            flushInterval: 100000,
            flushBatchSize: 100000,
            cache: new Psr16Cache(new ArrayAdapter()),
            httpClient: $this,
            requestFactory: $this->factory,
            streamFactory: $this->factory,
            logger: new NullLogger(),
        ));
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        if ($request->getMethod() === 'POST') {
            if (str_ends_with($request->getUri()->getPath(), '/v1/sdk/events')) {
                /** @var array{events: list<array<string, mixed>>} $body */
                $body = json_decode((string) $request->getBody(), true, 512, JSON_THROW_ON_ERROR);
                array_push($this->events, ...$body['events']);
            }

            return $this->factory->createResponse(200)->withBody($this->factory->createStream('{}'));
        }

        $body = json_encode([
            'environment' => 'test',
            'version' => 1,
            'flags' => $this->flags,
            'segments' => $this->segments,
        ], JSON_THROW_ON_ERROR);

        return $this->factory->createResponse(200)->withBody($this->factory->createStream($body));
    }
}
