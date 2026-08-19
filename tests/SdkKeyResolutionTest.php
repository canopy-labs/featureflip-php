<?php

declare(strict_types=1);

namespace Featureflip\Tests;

use Featureflip\{Config, FeatureflipClient};
use GuzzleHttp\Psr7\HttpFactory;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\{RequestInterface, ResponseInterface};
use Psr\Log\NullLogger;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Psr16Cache;

/**
 * Resolving the SDK key from the environment (#2261).
 *
 * The README has advertised `FEATUREFLIP_SDK_KEY` since the SDK shipped and
 * nothing ever read it. It is not a stray claim either: python
 * (`client.py`), go (`featureflip.go`), csharp (`FeatureflipClient.cs`) and
 * ruby (`client.rb`) all implement exactly this fallback, so PHP was the one
 * documenting a convention it did not honour.
 */
final class SdkKeyResolutionTest extends TestCase
{
    private const ENV_VAR = 'FEATUREFLIP_SDK_KEY';
    private const ENV_KEY = 'sdk_server_' . 'ZnJvbS10aGUtZW52aXJvbm1lbnQtbm90LXRoZS1jYWxsZXI';
    private const ARG_KEY = 'sdk_server_' . 'cGFzc2VkLWRpcmVjdGx5LWJ5LXRoZS1jYWxsZXItMTIzNDU';

    protected function tearDown(): void
    {
        FeatureflipClient::resetForTesting();
        putenv(self::ENV_VAR);   // unset — must not leak into sibling tests
    }

    public function testAnEmptyKeyFallsBackToTheEnvironment(): void
    {
        putenv(self::ENV_VAR . '=' . self::ENV_KEY);
        $api = new KeyEchoingApi();

        $this->client('', $api);

        $this->assertSame(self::ENV_KEY, $api->seenAuthorization);
    }

    public function testAnExplicitKeyWinsOverTheEnvironment(): void
    {
        putenv(self::ENV_VAR . '=' . self::ENV_KEY);
        $api = new KeyEchoingApi();

        $this->client(self::ARG_KEY, $api);

        $this->assertSame(self::ARG_KEY, $api->seenAuthorization);
    }

    public function testNeitherSourceIsAnError(): void
    {
        putenv(self::ENV_VAR);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage(self::ENV_VAR);

        $this->client('', new KeyEchoingApi());
    }

    /**
     * The resolved key — not the empty string the caller passed — has to be
     * what the core is cached under, or a second caller who names the key
     * explicitly would build a second core against the same environment.
     */
    public function testTheCoreIsCachedUnderTheResolvedKey(): void
    {
        putenv(self::ENV_VAR . '=' . self::ENV_KEY);
        $api = new KeyEchoingApi();

        $this->client('', $api);
        FeatureflipClient::get(self::ENV_KEY);   // no config: must find the cached core

        $this->assertSame(1, $api->fetches, 'Both handles must share one core');
    }

    private function client(string $key, KeyEchoingApi $api): FeatureflipClient
    {
        $factory = new HttpFactory();

        return FeatureflipClient::get($key, new Config(
            baseUrl: 'http://eval.test',
            pollInterval: 300,
            cache: new Psr16Cache(new ArrayAdapter()),
            httpClient: $api->withFactory($factory),
            requestFactory: $factory,
            streamFactory: $factory,
            logger: new NullLogger(),
        ));
    }
}

/** Records the Authorization header so a test can see which key was used. */
final class KeyEchoingApi implements ClientInterface
{
    public ?string $seenAuthorization = null;
    public int $fetches = 0;
    private HttpFactory $factory;

    public function withFactory(HttpFactory $factory): self
    {
        $this->factory = $factory;
        return $this;
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        if ($request->getMethod() === 'POST') {
            return $this->factory->createResponse(200)->withBody($this->factory->createStream('{}'));
        }

        $this->fetches++;
        $this->seenAuthorization = $request->getHeaderLine('Authorization');

        return $this->factory->createResponse(200)
            ->withBody($this->factory->createStream('{"flags":[],"segments":[]}'));
    }
}
