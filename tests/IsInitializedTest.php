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
use Symfony\Component\Cache\Psr16Cache as SharedCache;

/**
 * Whether the SDK holds a configuration at all (#2269).
 *
 * Since #2258 a config-load failure is survivable and logged — the SDK serves
 * last-known-good, or the caller's defaults if it never had anything. The host
 * application had no way to ask which of those it was: the state was computed
 * internally (to choose between the two halves of the warning message) and
 * never exposed. Every one of the six sibling server SDKs exposes it.
 *
 * The question is "has a configuration ever been loaded", NOT "is it fresh".
 * A snapshot retained through an outage is still a configuration — that is
 * precisely what #2258 exists to provide.
 */
final class IsInitializedTest extends TestCase
{
    protected function tearDown(): void
    {
        FeatureflipClient::resetForTesting();
    }

    public function testTrueAfterASuccessfulFetch(): void
    {
        $this->assertTrue($this->client(new StubApi())->isInitialized());
    }

    /**
     * The case that motivated the issue: nothing cached, the fetch failed, and
     * every flag is quietly serving its caller default.
     */
    public function testFalseWhenTheFetchFailedAndNothingWasCached(): void
    {
        $api = new StubApi();
        $api->down = true;

        $this->assertFalse($this->client($api)->isInitialized());
    }

    /**
     * Last-known-good IS a configuration. Reporting it as uninitialized would
     * contradict the retention #2258 added.
     */
    public function testTrueWhenServingLastKnownGoodThroughAnOutage(): void
    {
        $cache = new SharedCache(new ArrayAdapter());
        $api = new StubApi();

        $this->client($api, $cache);            // warm the cache
        FeatureflipClient::resetForTesting();   // fresh process
        $api->down = true;

        $this->assertTrue($this->client($api, $cache)->isInitialized());
    }

    /**
     * An environment with no flags yet loads successfully and is initialized.
     * This is why the store's `loaded` marker is the signal rather than
     * `isEmpty()`, which cannot tell "loaded, and empty" from "never loaded".
     */
    public function testTrueForAnEnvironmentWithNoFlags(): void
    {
        $api = new StubApi();
        $api->serveNoFlags = true;

        $this->assertTrue($this->client($api)->isInitialized());
    }

    public function testTrueForATestStub(): void
    {
        $this->assertTrue(FeatureflipClient::forTesting(['dark-mode' => true])->isInitialized());
    }

    public function testFalseOnceTheHandleIsClosed(): void
    {
        $client = $this->client(new StubApi());
        $this->assertTrue($client->isInitialized());

        $client->close();

        $this->assertFalse($client->isInitialized(), 'A closed handle is inert, and that includes this');
    }

    public function testASiblingHandleIsUnaffectedByAClose(): void
    {
        $first = $this->client(new StubApi());
        $second = FeatureflipClient::get('isinit-test');

        $first->close();

        $this->assertTrue($second->isInitialized(), 'Inertness is per handle, not per core');
    }

    private function client(StubApi $api, ?Psr16Cache $cache = null): FeatureflipClient
    {
        $factory = new HttpFactory();

        return FeatureflipClient::get('isinit-test', new Config(
            baseUrl: 'http://eval.test',
            pollInterval: 300,
            cache: $cache ?? new Psr16Cache(new ArrayAdapter()),
            httpClient: $api->withFactory($factory),
            requestFactory: $factory,
            streamFactory: $factory,
            logger: new NullLogger(),
        ));
    }
}

final class StubApi implements ClientInterface
{
    public bool $down = false;
    public bool $serveNoFlags = false;
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
        if ($this->down) {
            throw new \RuntimeException('eval api unreachable');
        }

        $flags = $this->serveNoFlags ? [] : [[
            'key' => 'flag-on', 'version' => 1, 'type' => 'boolean', 'enabled' => true,
            'variations' => [['key' => 'on', 'value' => true]], 'rules' => [],
            'fallthrough' => ['type' => 'Fixed', 'variation' => 'on'], 'offVariation' => null,
        ]];

        return $this->factory->createResponse(200)->withBody(
            $this->factory->createStream((string) json_encode(['flags' => $flags, 'segments' => []])),
        );
    }
}
