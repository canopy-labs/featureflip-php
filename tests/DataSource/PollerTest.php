<?php

declare(strict_types=1);

namespace Featureflip\Tests\DataSource;

use Featureflip\DataSource\Poller;
use Featureflip\Http\HttpClient;
use Featureflip\Store\FlagStore;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Psr16Cache;

final class PollerTest extends TestCase
{
    public function testFetchesFlagsAndStoresInStore(): void
    {
        $httpClient = $this->createMock(HttpClient::class);
        $httpClient->expects($this->once())->method('get')->with('/v1/sdk/flags')->willReturn([
            'flags' => [
                ['key' => 'flag-1', 'version' => 1, 'type' => 'boolean', 'enabled' => true, 'variations' => [['key' => 'on', 'value' => true]], 'rules' => [], 'fallthrough' => ['type' => 'Fixed', 'variation' => 'on'], 'offVariation' => null],
            ],
            'segments' => [],
        ]);

        $cache = new Psr16Cache(new ArrayAdapter());
        $store = new FlagStore($cache, 'test', 30);
        $poller = new Poller($httpClient, $store);

        $poller->fetch();

        $this->assertNotNull($store->getFlag('flag-1'));
        $this->assertTrue($store->getFlag('flag-1')->enabled);
    }

    public function testFetchesSegments(): void
    {
        $httpClient = $this->createMock(HttpClient::class);
        $httpClient->expects($this->once())->method('get')->with('/v1/sdk/flags')->willReturn([
            'flags' => [],
            'segments' => [
                ['key' => 'seg-1', 'version' => 1, 'conditions' => [], 'conditionLogic' => 'and'],
            ],
        ]);

        $cache = new Psr16Cache(new ArrayAdapter());
        $store = new FlagStore($cache, 'test', 30);
        $poller = new Poller($httpClient, $store);

        $poller->fetch();

        $this->assertArrayHasKey('seg-1', $store->getSegments());
    }
}
