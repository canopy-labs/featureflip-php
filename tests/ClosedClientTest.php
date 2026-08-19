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
 * What a closed handle does (#2267).
 *
 * `close()` used to mean three different things depending on which method you
 * called: inspectors and `refresh()` were suppressed, while the variation
 * accessors, `track()`, `identify()` and `flush()` carried on as if nothing had
 * happened — still reading the shut-down core's store, still reaching the
 * network. One rule now: a closed handle is inert.
 */
final class ClosedClientTest extends TestCase
{
    protected function tearDown(): void
    {
        FeatureflipClient::resetForTesting();
    }

    public function testTypedAccessorsReturnTheCallerDefault(): void
    {
        $client = $this->client();
        $this->assertTrue($client->boolVariation('flag-on', ['user_id' => 'u'], false), 'sanity: open handle serves the real value');

        $client->close();

        $this->assertFalse($client->boolVariation('flag-on', ['user_id' => 'u'], false));
        $this->assertSame('fallback', $client->stringVariation('flag-str', ['user_id' => 'u'], 'fallback'));
        $this->assertSame(-1, $client->numberVariation('flag-num', ['user_id' => 'u'], -1));
        $this->assertSame(['d' => 1], $client->jsonVariation('flag-json', ['user_id' => 'u'], ['d' => 1]));
    }

    /**
     * ERROR rather than a new PHP-only reason string: the reason vocabulary is
     * a cross-SDK contract, and ERROR already means "you got your default
     * because something prevented a real evaluation".
     */
    public function testVariationDetailReportsTheDefaultWithErrorReason(): void
    {
        $client = $this->client();
        $client->close();

        $detail = $client->variationDetail('flag-on', ['user_id' => 'u'], 'my-default');

        $this->assertSame('my-default', $detail->value);
        $this->assertSame('ERROR', $detail->reason);
    }

    public function testTrackAndIdentifyStopQueueing(): void
    {
        $api = new StubEvalApi();
        $client = $this->client($api);
        $client->close();
        $api->posts = 0;

        $client->track('checkout', ['user_id' => 'u'], ['total' => 1]);
        $client->identify(['user_id' => 'u', 'plan' => 'pro']);
        $client->flush();

        $this->assertSame(0, $api->posts, 'A closed handle must not reach the network');
    }

    public function testClosingIsStillWhatDeliversWhatWasAlreadyQueued(): void
    {
        $api = new StubEvalApi();
        $client = $this->client($api);
        $client->boolVariation('flag-on', ['user_id' => 'u'], false);
        $api->posts = 0;

        $client->close();

        $this->assertSame(1, $api->posts, 'close() still flushes — inertness starts after it, not during');
    }

    public function testASiblingHandleIsUnaffected(): void
    {
        $api = new StubEvalApi();
        $first = $this->client($api);
        $second = FeatureflipClient::get('closed-test');

        $first->close();

        $this->assertTrue(
            $second->boolVariation('flag-on', ['user_id' => 'u'], false),
            'Inertness is per handle, not per core — closing one must not disable another',
        );
    }

    // --- initTimeout is gone -----------------------------------------------

    /**
     * It never did anything and cannot: PSR-18's sendRequest() offers the SDK
     * no timeout or cancellation hook, so a client the SDK did not construct
     * cannot be bounded by it. Timeouts belong on the injected client.
     */
    public function testConfigNoLongerAcceptsInitTimeout(): void
    {
        $params = array_map(
            static fn (\ReflectionParameter $p): string => $p->getName(),
            (new \ReflectionClass(Config::class))->getConstructor()?->getParameters() ?? [],
        );

        $this->assertNotContains('initTimeout', $params);
    }

    // --- Fixtures ----------------------------------------------------------

    private function client(?StubEvalApi $api = null): FeatureflipClient
    {
        $factory = new HttpFactory();
        $api ??= new StubEvalApi();

        return FeatureflipClient::get('closed-test', new Config(
            baseUrl: 'http://eval.test',
            pollInterval: 300,
            flushBatchSize: 100000,
            flushInterval: 100000,
            cache: new Psr16Cache(new ArrayAdapter()),
            httpClient: $api->withFactory($factory),
            requestFactory: $factory,
            streamFactory: $factory,
            logger: new NullLogger(),
        ));
    }
}

final class StubEvalApi implements ClientInterface
{
    public int $posts = 0;
    private HttpFactory $factory;

    public function withFactory(HttpFactory $factory): self
    {
        $this->factory = $factory;
        return $this;
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        if ($request->getMethod() === 'POST') {
            $this->posts++;
            return $this->factory->createResponse(200)->withBody($this->factory->createStream('{}'));
        }

        $body = json_encode(['flags' => [[
            'key' => 'flag-on', 'version' => 1, 'type' => 'boolean', 'enabled' => true,
            'variations' => [['key' => 'on', 'value' => true], ['key' => 'off', 'value' => false]],
            'rules' => [], 'fallthrough' => ['type' => 'Fixed', 'variation' => 'on'], 'offVariation' => 'off',
        ]], 'segments' => []]);

        return $this->factory->createResponse(200)->withBody($this->factory->createStream((string) $body));
    }
}
