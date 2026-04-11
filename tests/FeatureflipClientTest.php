<?php

declare(strict_types=1);

namespace Featureflip\Tests;

use Featureflip\Config;
use Featureflip\FeatureflipClient;
use Featureflip\SharedFeatureflipCore;
use Featureflip\Model\{Flag, ServeConfig, Variation};
use Featureflip\Store\FlagStore;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Psr7\HttpFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Psr16Cache;

final class FeatureflipClientTest extends TestCase
{
    protected function tearDown(): void
    {
        FeatureflipClient::resetForTesting();
    }

    private function makeConfig(): Config
    {
        $cache = new Psr16Cache(new ArrayAdapter());
        $httpFactory = new HttpFactory();

        return new Config(
            baseUrl: 'http://localhost:9999',
            cache: $cache,
            httpClient: new GuzzleClient(['timeout' => 1, 'connect_timeout' => 1]),
            requestFactory: $httpFactory,
            streamFactory: $httpFactory,
        );
    }

    private function getCore(FeatureflipClient $client): SharedFeatureflipCore
    {
        $ref = new \ReflectionClass($client);
        $prop = $ref->getProperty('core');

        return $prop->getValue($client);
    }

    // --- Factory & refcount tests ---

    public function testSameKeySameCore(): void
    {
        $config = $this->makeConfig();
        $h1 = FeatureflipClient::get('key-a', $config);
        $h2 = FeatureflipClient::get('key-a');

        $this->assertSame($this->getCore($h1), $this->getCore($h2));

        $h1->close();
        $h2->close();
    }

    public function testDifferentKeysIndependentCores(): void
    {
        $config = $this->makeConfig();
        $h1 = FeatureflipClient::get('key-a', $config);
        $h2 = FeatureflipClient::get('key-b', $config);

        $this->assertNotSame($this->getCore($h1), $this->getCore($h2));

        $h1->close();
        $h2->close();
    }

    public function testCloseLastHandleRemovesFromCache(): void
    {
        $config = $this->makeConfig();
        $h1 = FeatureflipClient::get('key-a', $config);
        $core1 = $this->getCore($h1);
        $h1->close();

        $h2 = FeatureflipClient::get('key-a', $config);
        $core2 = $this->getCore($h2);

        $this->assertNotSame($core1, $core2);
    }

    public function testPartialCloseKeepsOtherHandleFunctional(): void
    {
        $config = $this->makeConfig();
        $h1 = FeatureflipClient::get('key-a', $config);
        $h2 = FeatureflipClient::get('key-a');
        $h1->close();

        $h3 = FeatureflipClient::get('key-a');
        $this->assertSame($this->getCore($h2), $this->getCore($h3));

        $h2->close();
        $h3->close();
    }

    public function testDoubleCloseIsIdempotent(): void
    {
        $config = $this->makeConfig();
        $h1 = FeatureflipClient::get('key-a', $config);
        $h2 = FeatureflipClient::get('key-a');

        $h1->close();
        $h1->close(); // double close — should not double-decrement

        // h2 should still share the same core with a new handle
        $h3 = FeatureflipClient::get('key-a');
        $this->assertSame($this->getCore($h2), $this->getCore($h3));

        $h2->close();
        $h3->close();
    }

    public function testGetWithoutConfigOnFirstCallThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        FeatureflipClient::get('uncached-key');
    }

    public function testForTestingDoesNotEnterCache(): void
    {
        FeatureflipClient::forTesting(['flag' => true]);

        $this->expectException(\InvalidArgumentException::class);
        FeatureflipClient::get('any-key');
    }

    // --- forTesting evaluation tests ---

    public function testForTestingBoolVariation(): void
    {
        $client = FeatureflipClient::forTesting(['dark-mode' => true, 'light-mode' => false]);

        $this->assertTrue($client->boolVariation('dark-mode', [], false));
        $this->assertFalse($client->boolVariation('light-mode', [], true));
    }

    public function testForTestingStringVariation(): void
    {
        $client = FeatureflipClient::forTesting(['tier' => 'pro']);

        $this->assertSame('pro', $client->stringVariation('tier', [], 'free'));
    }

    public function testForTestingNumberVariation(): void
    {
        $client = FeatureflipClient::forTesting(['limit' => 500]);

        $this->assertSame(500, $client->numberVariation('limit', [], 100));
    }

    public function testForTestingJsonVariation(): void
    {
        $client = FeatureflipClient::forTesting(['config' => ['sidebar' => true]]);

        $this->assertSame(['sidebar' => true], $client->jsonVariation('config', [], []));
    }

    public function testForTestingReturnDefaultForMissing(): void
    {
        $client = FeatureflipClient::forTesting([]);

        $this->assertFalse($client->boolVariation('missing', [], false));
        $this->assertSame('default', $client->stringVariation('missing', [], 'default'));
    }

    public function testForTestingVariationDetail(): void
    {
        $client = FeatureflipClient::forTesting(['flag' => true]);

        $detail = $client->variationDetail('flag', [], false);
        $this->assertTrue($detail->value);
        $this->assertSame('FALLTHROUGH', $detail->reason);
    }

    public function testForTestingVariationDetailMissing(): void
    {
        $client = FeatureflipClient::forTesting([]);

        $detail = $client->variationDetail('missing', [], false);
        $this->assertFalse($detail->value);
        $this->assertSame('FLAG_NOT_FOUND', $detail->reason);
    }

    // --- Null value preservation test ---

    public function testEvaluateFlagPreservesLegitimateNullValue(): void
    {
        $cache = new Psr16Cache(new ArrayAdapter());
        $store = new FlagStore($cache, 'test-key', 30);

        $flag = new Flag(
            key: 'null-flag',
            version: 1,
            type: 'json',
            enabled: true,
            variations: [new Variation('null-var', null)],
            rules: [],
            fallthrough: new ServeConfig('Fixed', 'null-var', null, null, null),
            offVariation: null,
        );

        $store->putAll([$flag], []);

        // Build a SharedFeatureflipCore with the custom store via reflection
        $coreRef = new \ReflectionClass(SharedFeatureflipCore::class);
        $coreCtor = $coreRef->getConstructor();
        $coreCtor->setAccessible(true);

        $core = $coreRef->newInstanceWithoutConstructor();
        $coreCtor->invoke($core, $store, null, null, null);

        // Build a FeatureflipClient handle wrapping this core
        $clientRef = new \ReflectionClass(FeatureflipClient::class);
        $clientCtor = $clientRef->getConstructor();
        $clientCtor->setAccessible(true);

        $client = $clientRef->newInstanceWithoutConstructor();
        $clientCtor->invoke($client, $core);

        $detail = $client->variationDetail('null-flag', ['user_id' => 'u1'], 'fallback');

        $this->assertNull($detail->value, 'Legitimate null value should not be replaced with default');
        $this->assertSame('FALLTHROUGH', $detail->reason);
    }
}
