<?php

declare(strict_types=1);

namespace Featureflip\Tests;

use Featureflip\Client;
use Featureflip\Model\{Flag, ServeConfig, Variation};
use Featureflip\Store\FlagStore;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Psr16Cache;

final class ClientTest extends TestCase
{
    private function createClientWithStore(FlagStore $store): Client
    {
        $ref = new \ReflectionClass(Client::class);
        $ctor = $ref->getConstructor();
        $ctor->setAccessible(true);

        $client = $ref->newInstanceWithoutConstructor();
        $ctor->invoke($client, $store, null, null, null);

        return $client;
    }
    public function testForTestingBoolVariation(): void
    {
        $client = Client::forTesting(['dark-mode' => true, 'light-mode' => false]);

        $this->assertTrue($client->boolVariation('dark-mode', [], false));
        $this->assertFalse($client->boolVariation('light-mode', [], true));
    }

    public function testForTestingStringVariation(): void
    {
        $client = Client::forTesting(['tier' => 'pro']);

        $this->assertSame('pro', $client->stringVariation('tier', [], 'free'));
    }

    public function testForTestingNumberVariation(): void
    {
        $client = Client::forTesting(['limit' => 500]);

        $this->assertSame(500, $client->numberVariation('limit', [], 100));
    }

    public function testForTestingJsonVariation(): void
    {
        $client = Client::forTesting(['config' => ['sidebar' => true]]);

        $this->assertSame(['sidebar' => true], $client->jsonVariation('config', [], []));
    }

    public function testForTestingReturnDefaultForMissing(): void
    {
        $client = Client::forTesting([]);

        $this->assertFalse($client->boolVariation('missing', [], false));
        $this->assertSame('default', $client->stringVariation('missing', [], 'default'));
    }

    public function testForTestingVariationDetail(): void
    {
        $client = Client::forTesting(['flag' => true]);

        $detail = $client->variationDetail('flag', [], false);
        $this->assertTrue($detail->value);
        $this->assertSame('FALLTHROUGH', $detail->reason);
    }

    public function testForTestingVariationDetailMissing(): void
    {
        $client = Client::forTesting([]);

        $detail = $client->variationDetail('missing', [], false);
        $this->assertFalse($detail->value);
        $this->assertSame('FLAG_NOT_FOUND', $detail->reason);
    }

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

        $client = $this->createClientWithStore($store);
        $detail = $client->variationDetail('null-flag', ['user_id' => 'u1'], 'fallback');

        $this->assertNull($detail->value, 'Legitimate null value should not be replaced with default');
        $this->assertSame('FALLTHROUGH', $detail->reason);
    }
}
