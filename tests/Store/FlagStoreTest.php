<?php

declare(strict_types=1);

namespace Featureflip\Tests\Store;

use Featureflip\Store\FlagStore;
use Featureflip\Model\{Flag, Segment, Variation, ServeConfig};
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
}
