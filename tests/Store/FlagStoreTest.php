<?php

declare(strict_types=1);

namespace Featureflip\Tests\Store;

use Featureflip\Store\FlagStore;
use Featureflip\Model\{Flag, Prerequisite, Segment, Variation, ServeConfig};
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Psr16Cache;

final class FlagStoreTest extends TestCase
{
    private function createCache(): Psr16Cache
    {
        return new Psr16Cache(new ArrayAdapter());
    }

    public function testStoreAndRetrieveFlags(): void
    {
        $cache = $this->createCache();
        $store = new FlagStore($cache, 'test-key', 30);

        $flags = [
            new Flag('flag-1', 1, 'boolean', true, [new Variation('on', true)], [], new ServeConfig('Fixed', 'on', null, null, null), null),
        ];
        $segments = [];

        $store->putAll($flags, $segments);

        $this->assertNotNull($store->getFlag('flag-1'));
        $this->assertSame('flag-1', $store->getFlag('flag-1')->key);
    }

    public function testGetFlagReturnsNullForMissing(): void
    {
        $cache = $this->createCache();
        $store = new FlagStore($cache, 'test-key', 30);

        $this->assertNull($store->getFlag('nonexistent'));
    }

    public function testGetAllSegments(): void
    {
        $cache = $this->createCache();
        $store = new FlagStore($cache, 'test-key', 30);

        $segments = [
            new Segment('seg-1', 1, [], 'and'),
        ];

        $store->putAll([], $segments);

        $allSegments = $store->getSegments();
        $this->assertArrayHasKey('seg-1', $allSegments);
    }

    public function testIsExpiredWhenEmpty(): void
    {
        $cache = $this->createCache();
        $store = new FlagStore($cache, 'test-key', 30);

        $this->assertTrue($store->isExpired());
    }

    public function testIsNotExpiredAfterPut(): void
    {
        $cache = $this->createCache();
        $store = new FlagStore($cache, 'test-key', 30);

        $store->putAll([], []);
        $this->assertFalse($store->isExpired());
    }

    public function testCacheRoundtripPreservesPrerequisites(): void
    {
        // Build a flag, put it into one store, then load a fresh store from
        // the same cache. The prerequisites array must survive the JSON
        // serialisation roundtrip used by FlagStore.
        $cache = $this->createCache();
        $store = new FlagStore($cache, 'test-key', 30);

        $flag = new Flag(
            key: 'main',
            version: 1,
            type: 'boolean',
            enabled: true,
            variations: [new Variation('on', true), new Variation('off', false)],
            rules: [],
            fallthrough: new ServeConfig('Fixed', 'on', null, null, null),
            offVariation: 'off',
            prerequisites: [new Prerequisite('billing-enabled', 'on')],
        );
        $store->putAll([$flag], []);

        $reloaded = new FlagStore($cache, 'test-key', 30);
        $loadedFlag = $reloaded->getFlag('main');
        $this->assertNotNull($loadedFlag);
        $this->assertCount(1, $loadedFlag->prerequisites);
        $this->assertSame('billing-enabled', $loadedFlag->prerequisites[0]->prerequisiteFlagKey);
        $this->assertSame('on', $loadedFlag->prerequisites[0]->expectedVariationKey);
    }

    public function testGetFlagsReturnsAllFlagsKeyedByKey(): void
    {
        $cache = $this->createCache();
        $store = new FlagStore($cache, 'test-key', 30);

        $flags = [
            new Flag('flag-a', 1, 'boolean', true, [new Variation('on', true)], [], new ServeConfig('Fixed', 'on', null, null, null), null),
            new Flag('flag-b', 1, 'boolean', true, [new Variation('on', true)], [], new ServeConfig('Fixed', 'on', null, null, null), null),
        ];
        $store->putAll($flags, []);

        $all = $store->getFlags();
        $this->assertCount(2, $all);
        $this->assertArrayHasKey('flag-a', $all);
        $this->assertArrayHasKey('flag-b', $all);
    }
}
