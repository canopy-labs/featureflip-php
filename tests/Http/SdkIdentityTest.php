<?php

declare(strict_types=1);

namespace Featureflip\Tests\Http;

use Featureflip\Config;
use Featureflip\FeatureflipClient;
use Featureflip\Http\HttpClient;
use GuzzleHttp\Psr7\HttpFactory;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\{RequestInterface, ResponseInterface};
use Psr\Log\AbstractLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Psr16Cache;

/**
 * The SDK token plus a wrapper's token in the User-Agent (#4152, contract in #4138).
 * featureflip-php/<version> was already sent; what was missing is a way for a library
 * that wraps the SDK (the OpenFeature provider) to name itself. Asserted on the wire
 * for the flag fetch and the event post, the SDK's only two requests.
 */
final class SdkIdentityTest extends TestCase
{
    private string $sdkToken;

    protected function setUp(): void
    {
        $this->sdkToken = 'featureflip-php/' . (new \ReflectionClassConstant(HttpClient::class, 'VERSION'))->getValue();
    }

    protected function tearDown(): void
    {
        FeatureflipClient::resetForTesting();
    }

    public function testNoWrapperSendsTheBareSdkTokenOnFetchAndPost(): void
    {
        $transport = new RecordingTransport();
        $client = $this->httpClient($transport, '');

        $client->get('/v1/sdk/flags');
        $client->post('/v1/sdk/events', ['events' => []]);

        $this->assertSame([$this->sdkToken, $this->sdkToken], $transport->agents);
    }

    public function testAWrapperTokenIsAppendedOnFetchAndPost(): void
    {
        $transport = new RecordingTransport();
        $client = $this->httpClient($transport, 'featureflip-openfeature-php/1.2.0');

        $client->get('/v1/sdk/flags');
        $client->post('/v1/sdk/events', ['events' => []]);

        $expected = $this->sdkToken . ' featureflip-openfeature-php/1.2.0';
        $this->assertSame([$expected, $expected], $transport->agents);
    }

    public function testWrapperTokenValidation(): void
    {
        $this->assertSame('', HttpClient::wrapperToken(null, null));
        $this->assertSame('w/1.0', HttpClient::wrapperToken('w', '1.0'));
        $this->assertSame('my-wrapper', HttpClient::wrapperToken('my-wrapper', null));
        // A space is not a legal product-token character, and a PSR-7 header value
        // would carry it regardless.
        $this->assertNull(HttpClient::wrapperToken('bad name', '1.0'));
        $this->assertNull(HttpClient::wrapperToken('ok', 'bad version'));
    }

    public function testAConfiguredWrapperReachesTheWireThroughTheClient(): void
    {
        $transport = new RecordingTransport();

        $this->featureflip($transport, new IdentityLogger(), 'featureflip-openfeature-php', '1.2.0');

        $this->assertSame($this->sdkToken . ' featureflip-openfeature-php/1.2.0', $transport->agents[0]);
    }

    public function testAnInvalidWrapperIsDroppedWithAWarning(): void
    {
        // Identity metadata must never stop the client starting, so the wrapper is
        // dropped and said so.
        $transport = new RecordingTransport();
        $logger = new IdentityLogger();

        $this->featureflip($transport, $logger, 'bad name', '1.0');

        $this->assertSame($this->sdkToken, $transport->agents[0]);
        $this->assertTrue($logger->has('warning', 'bad name'));
    }

    private function httpClient(RecordingTransport $transport, string $wrapper): HttpClient
    {
        $factory = new HttpFactory();

        return new HttpClient($transport->withFactory($factory), $factory, $factory, 'sdk-key', 'http://eval.test', $wrapper);
    }

    private function featureflip(RecordingTransport $transport, IdentityLogger $logger, string $name, string $version): void
    {
        $factory = new HttpFactory();
        $client = FeatureflipClient::get('identity-key', new Config(
            baseUrl: 'http://eval.test',
            cache: new Psr16Cache(new ArrayAdapter()),
            httpClient: $transport->withFactory($factory),
            requestFactory: $factory,
            streamFactory: $factory,
            logger: $logger,
            wrapperName: $name,
            wrapperVersion: $version,
        ));
        $client->boolVariation('any-flag', ['user_id' => 'u'], false);
    }
}

/** A PSR-18 client that records each request's User-Agent and answers with an empty config. */
final class RecordingTransport implements ClientInterface
{
    /** @var list<string> */
    public array $agents = [];
    private HttpFactory $factory;

    public function withFactory(HttpFactory $factory): self
    {
        $this->factory = $factory;
        return $this;
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->agents[] = $request->getHeaderLine('User-Agent');

        return $this->factory->createResponse(200)
            ->withBody($this->factory->createStream('{"environment":"e","version":1,"flags":[],"segments":[]}'));
    }
}

final class IdentityLogger extends AbstractLogger
{
    /** @var list<array{string, string}> */
    public array $entries = [];

    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $this->entries[] = [(string) $level, $message . ' ' . json_encode($context)];
    }

    public function has(string $level, string $needle): bool
    {
        foreach ($this->entries as [$l, $m]) {
            if ($l === $level && str_contains($m, $needle)) {
                return true;
            }
        }
        return false;
    }
}
