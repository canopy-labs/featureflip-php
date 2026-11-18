<?php

declare(strict_types=1);

namespace Featureflip\Tests;

use Featureflip\{Config, FeatureflipClient};
use Featureflip\Model\{Flag, ServeConfig, Variation};
use Featureflip\Store\FlagStore;
use GuzzleHttp\Psr7\HttpFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\{RequestInterface, ResponseInterface};
use Psr\Log\{AbstractLogger, LoggerInterface};
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Psr16Cache;

/**
 * A failing PSR-16 cache must never reach the caller (#5777).
 *
 * The cache is the host application's: Laravel hands the SDK its default store,
 * so a database store whose table is not migrated yet, or a Redis store with
 * Redis down, throws `QueryException` or a Redis exception from every call.
 * Those are not PSR exceptions, so the SDK guards against any `\Throwable`, and
 * the evaluation methods keep their "never throws" contract.
 */
final class CacheFailureTest extends TestCase
{
    protected function tearDown(): void
    {
        FeatureflipClient::resetForTesting();
    }

    /** @return iterable<string, array{\Closure(): \Throwable}> */
    public static function cacheErrors(): iterable
    {
        yield 'driver exception (QueryException, Redis)' => [
            static fn (): \Throwable => new \RuntimeException('SQLSTATE[42P01]: relation "cache" does not exist'),
        ];
        yield 'PSR-16 InvalidArgumentException' => [
            static fn (): \Throwable => new PsrCacheInvalidArgument('cache key rejected'),
        ];
    }

    #[DataProvider('cacheErrors')]
    public function testClientConstructsAndEvaluatesWhenEveryCacheCallThrows(\Closure $error): void
    {
        $cache = new ThrowingCache(new Psr16Cache(new ArrayAdapter()), $error);
        $cache->broken = true;
        $api = new CacheFailureEvalApi();
        $logger = new CacheFailureLogger();

        $client = $this->client($cache, $api, $logger);

        // Cold start (the snapshot read failed), then the fetched configuration
        // is served from memory even though it could not be written back.
        $this->assertTrue($client->boolVariation('kill-switch', ['user_id' => 'u'], false));
        $this->assertTrue($client->boolVariation('kill-switch', ['user_id' => 'u'], false));
        $this->assertSame(1, $api->fetches, 'A fresh in-memory configuration must not be refetched');
        $this->assertTrue($logger->contains('cache'), "The cache failure must be logged: {$logger}");
    }

    #[DataProvider('cacheErrors')]
    public function testUnreachableApiAndCacheServeTheCallerDefault(\Closure $error): void
    {
        $cache = new ThrowingCache(new Psr16Cache(new ArrayAdapter()), $error);
        $cache->broken = true;
        $api = new CacheFailureEvalApi();
        $api->down = true;
        $logger = new CacheFailureLogger();

        $client = $this->client($cache, $api, $logger);

        $detail = $client->variationDetail('kill-switch', ['user_id' => 'u'], false);
        $this->assertFalse($detail->value);
        $this->assertSame('FLAG_NOT_FOUND', $detail->reason);
        $this->assertTrue($logger->contains('cache'), "The cache failure must be logged: {$logger}");
        $this->assertTrue($logger->contains('eval api unreachable'), "The fetch failure must still be logged: {$logger}");
    }

    public function testAStaleStoreKeepsServingWhenTheCacheStartsThrowing(): void
    {
        $cache = new ThrowingCache(
            new Psr16Cache(new ArrayAdapter()),
            static fn (): \Throwable => new \RuntimeException('Connection refused [tcp://redis:6379]'),
        );
        $api = new CacheFailureEvalApi();
        $logger = new CacheFailureLogger();
        $client = $this->client($cache, $api, $logger, pollInterval: 30);
        $this->assertTrue($client->boolVariation('kill-switch', ['user_id' => 'u'], false));
        $this->assertSame(1, $api->fetches);

        // The cache backend and the evaluation API both go down, and the held
        // configuration falls due for a refresh.
        $cache->broken = true;
        $api->down = true;
        $this->makeStale($client);

        for ($i = 0; $i < 20; $i++) {
            $this->assertTrue(
                $client->boolVariation('kill-switch', ['user_id' => 'u'], false),
                'The last known good configuration must keep serving',
            );
        }
        $this->assertSame(2, $api->fetches, 'One refresh attempt, not one per evaluation');

        // Once the one-second in-process throttle lapses, the backoff must still
        // hold: its shared marker could not be written, so it is held in-process
        // for the poll interval instead of re-dialling the API every second.
        $this->releaseThrottle($client);
        $this->assertTrue($client->boolVariation('kill-switch', ['user_id' => 'u'], false));
        $this->assertSame(2, $api->fetches, 'A failed backoff read must not turn into a fetch per second');

        $this->assertTrue($logger->contains('connection refused'), "The cache failure must be logged: {$logger}");
    }

    public function testRecoveryAfterTheBackoffWhileTheCacheIsStillDown(): void
    {
        $cache = new ThrowingCache(
            new Psr16Cache(new ArrayAdapter()),
            static fn (): \Throwable => new \RuntimeException('cache down'),
        );
        $cache->broken = true;
        $api = new CacheFailureEvalApi();
        $api->down = true;
        $client = $this->client($cache, $api, new CacheFailureLogger(), pollInterval: 30);
        $this->assertFalse($client->boolVariation('kill-switch', ['user_id' => 'u'], false));
        $this->assertSame(1, $api->fetches);

        // The API comes back and the in-process backoff runs out.
        $api->down = false;
        $this->releaseThrottle($client);
        $store = $this->store($client);
        (new \ReflectionProperty(FlagStore::class, 'localBackoffUntil'))->setValue($store, time() - 1);

        $this->assertTrue($client->boolVariation('kill-switch', ['user_id' => 'u'], false));
        $this->assertSame(2, $api->fetches);
    }

    public function testFlagStoreDegradesWhenEveryCacheCallThrows(): void
    {
        $cache = new ThrowingCache(
            new Psr16Cache(new ArrayAdapter()),
            static fn (): \Throwable => new \RuntimeException('cache down'),
        );
        $cache->broken = true;
        $logger = new CacheFailureLogger();

        $store = new FlagStore($cache, 'hash', 30, $logger);
        $this->assertFalse($store->isLoaded(), 'A failed snapshot read starts cold');
        $this->assertFalse($store->isRefreshBackedOff(), 'An unreadable marker is not a backoff on its own');

        $store->recordFailedRefresh();
        $this->assertTrue($store->isRefreshBackedOff(), 'An unwritable marker is held in-process instead');

        $store->putAll([$this->flag()], []);
        $this->assertNotNull($store->getFlag('kill-switch'), 'A failed write leaves the in-memory store working');
        $this->assertFalse($store->isExpired());
        $this->assertFalse($store->isRefreshBackedOff(), 'A successful fetch clears the in-process backoff');
        $this->assertTrue($logger->contains('cache down'), "Each failure must be logged: {$logger}");
    }

    public function testAThrowingLoggerOnTopOfAThrowingCacheStillNeverEscapes(): void
    {
        $cache = new ThrowingCache(
            new Psr16Cache(new ArrayAdapter()),
            static fn (): \Throwable => new \RuntimeException('cache down'),
        );
        $cache->broken = true;

        $store = new FlagStore($cache, 'hash', 30, new CacheFailureThrowingLogger());
        $store->recordFailedRefresh();
        $store->putAll([$this->flag()], []);

        $this->assertTrue($store->isRefreshBackedOff() === false);
        $this->assertNotNull($store->getFlag('kill-switch'));
    }

    public function testAHealthyCacheStillSharesTheBackoffMarkerAcrossProcesses(): void
    {
        $inner = new Psr16Cache(new ArrayAdapter());
        $first = new FlagStore($inner, 'hash', 30);
        $first->recordFailedRefresh();

        // Another worker sees the marker; a successful fetch elsewhere clears it.
        $second = new FlagStore($inner, 'hash', 30);
        $this->assertTrue($second->isRefreshBackedOff());
        $second->putAll([$this->flag()], []);
        $this->assertFalse($first->isRefreshBackedOff(), 'A written marker is not also held in-process');
    }

    // --- Fixtures ----------------------------------------------------------

    private function store(FeatureflipClient $client): FlagStore
    {
        $core = (new \ReflectionProperty(FeatureflipClient::class, 'core'))->getValue($client);

        return (new \ReflectionProperty($core::class, 'store'))->getValue($core);
    }

    private function makeStale(FeatureflipClient $client): void
    {
        (new \ReflectionProperty(FlagStore::class, 'fetchedAt'))->setValue($this->store($client), time() - 3600);
    }

    private function releaseThrottle(FeatureflipClient $client): void
    {
        $core = (new \ReflectionProperty(FeatureflipClient::class, 'core'))->getValue($client);
        (new \ReflectionProperty($core::class, 'nextRefreshCheckAt'))->setValue($core, null);
    }

    private function flag(): Flag
    {
        return new Flag(
            key: 'kill-switch',
            version: 1,
            type: 'boolean',
            enabled: true,
            variations: [new Variation('on', true), new Variation('off', false)],
            rules: [],
            fallthrough: new ServeConfig('Fixed', 'on', null, null, null),
            offVariation: 'off',
        );
    }

    private function client(
        \Psr\SimpleCache\CacheInterface $cache,
        CacheFailureEvalApi $api,
        LoggerInterface $logger,
        int $pollInterval = 30,
    ): FeatureflipClient {
        $factory = new HttpFactory();

        return FeatureflipClient::get('cache-failure-key', new Config(
            baseUrl: 'http://eval.test',
            pollInterval: $pollInterval,
            cache: $cache,
            httpClient: $api->withFactory($factory),
            requestFactory: $factory,
            streamFactory: $factory,
            logger: $logger,
        ));
    }
}

/** A cache whose every call throws while `$broken`, like a store whose backend is down. */
final class ThrowingCache implements \Psr\SimpleCache\CacheInterface
{
    public bool $broken = false;

    /** @param \Closure(): \Throwable $error */
    public function __construct(private \Psr\SimpleCache\CacheInterface $inner, private \Closure $error) {}

    private function check(): void
    {
        if ($this->broken) {
            throw ($this->error)();
        }
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $this->check();
        return $this->inner->get($key, $default);
    }

    public function set(string $key, mixed $value, null|int|\DateInterval $ttl = null): bool
    {
        $this->check();
        return $this->inner->set($key, $value, $ttl);
    }

    public function delete(string $key): bool
    {
        $this->check();
        return $this->inner->delete($key);
    }

    public function clear(): bool
    {
        $this->check();
        return $this->inner->clear();
    }

    public function getMultiple(iterable $keys, mixed $default = null): iterable
    {
        $this->check();
        return $this->inner->getMultiple($keys, $default);
    }

    /** @param iterable<string, mixed> $values */
    public function setMultiple(iterable $values, null|int|\DateInterval $ttl = null): bool
    {
        $this->check();
        return $this->inner->setMultiple($values, $ttl);
    }

    public function deleteMultiple(iterable $keys): bool
    {
        $this->check();
        return $this->inner->deleteMultiple($keys);
    }

    public function has(string $key): bool
    {
        $this->check();
        return $this->inner->has($key);
    }
}

final class PsrCacheInvalidArgument extends \InvalidArgumentException implements \Psr\SimpleCache\InvalidArgumentException
{
}

/** A logger that fails the way Monolog does when its stream cannot be opened. */
final class CacheFailureThrowingLogger extends AbstractLogger
{
    public function log($level, string|\Stringable $message, array $context = []): void
    {
        throw new \UnexpectedValueException('The stream or file could not be opened');
    }
}

/** The evaluation API: serves one boolean flag, or throws while `$down`. */
final class CacheFailureEvalApi implements ClientInterface
{
    public bool $down = false;
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
        if ($this->down) {
            throw new \RuntimeException('eval api unreachable');
        }

        $body = json_encode([
            'flags' => [[
                'key' => 'kill-switch', 'version' => 1, 'type' => 'boolean', 'enabled' => true,
                'variations' => [['key' => 'on', 'value' => true], ['key' => 'off', 'value' => false]],
                'rules' => [], 'fallthrough' => ['type' => 'Fixed', 'variation' => 'on'], 'offVariation' => 'off',
            ]],
            'segments' => [],
        ]);

        return $this->factory->createResponse(200)->withBody($this->factory->createStream((string) $body));
    }
}

final class CacheFailureLogger extends AbstractLogger
{
    /** @var list<string> */
    public array $lines = [];

    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $this->lines[] = strtolower($level . ' ' . $message);
    }

    public function contains(string $needle): bool
    {
        foreach ($this->lines as $line) {
            if (str_contains($line, strtolower($needle))) {
                return true;
            }
        }
        return false;
    }

    public function __toString(): string
    {
        return $this->lines === [] ? '(nothing logged)' : implode(' | ', $this->lines);
    }
}
