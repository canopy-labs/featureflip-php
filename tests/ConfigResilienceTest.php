<?php

declare(strict_types=1);

namespace Featureflip\Tests;

use Featureflip\{Config, FeatureflipClient};
use Featureflip\Model\{Flag, Segment, ServeConfig, Variation};
use Featureflip\Store\FlagStore;
use GuzzleHttp\Psr7\HttpFactory;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\{RequestInterface, ResponseInterface};
use Psr\Log\{AbstractLogger, LoggerInterface, NullLogger};
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Psr16Cache;

/**
 * What the SDK does when it cannot load a flag configuration (#2258).
 *
 * The governing rule is that a config-load failure must never silently turn
 * every flag off. Three separate defects combined to do exactly that: cache
 * entries were written with `ttl = pollInterval`, so they expired at the very
 * moment they became refetchable and there was no last-known-good to fall back
 * on; a single malformed flag aborted the whole snapshot; and every failure was
 * swallowed by a variable-less `catch (\Throwable)` that could not log even if
 * it wanted to.
 */
final class ConfigResilienceTest extends TestCase
{
    protected function tearDown(): void
    {
        FeatureflipClient::resetForTesting();
    }

    // --- Last-known-good ---------------------------------------------------

    public function testAWarmCacheSurvivesAnEvalApiOutageBeyondThePollInterval(): void
    {
        $cache = $this->cache();
        $api = new FakeEvalApi();

        // Request 1: healthy API populates the cache.
        $this->client('k', $cache, $api)->boolVariation('kill-switch', ['user_id' => 'u'], false);

        // The eval API goes down and the poll interval lapses.
        $api->down = true;
        sleep(2);

        // Request 2 is a fresh process: cold in-memory state, shared cache.
        FeatureflipClient::resetForTesting();
        $detail = $this->client('k', $cache, $api)->variationDetail('kill-switch', ['user_id' => 'u'], false);

        $this->assertTrue($detail->value, 'A flag must keep its real value through an outage, not revert to the default');
        $this->assertSame('FALLTHROUGH', $detail->reason);
    }

    public function testCachedConfigOutlivesThePollInterval(): void
    {
        $cache = $this->cache();
        $store = new FlagStore($cache, 'hash', 1);
        $store->putAll([$this->flag()], []);

        sleep(2);

        $reloaded = new FlagStore($cache, 'hash', 1);

        $this->assertNotNull($reloaded->getFlag('kill-switch'), 'Config is retained indefinitely; only a successful fetch replaces it');
        $this->assertTrue($reloaded->isExpired(), 'It is still due a refresh — retention and freshness are separate concerns');
    }

    /**
     * A snapshot that cannot be parsed back must not be fatal — and must not be
     * permanent. Removing the cache TTL means nothing ages a poisoned entry out
     * any more, so the store has to evict it itself; otherwise a corrupt write
     * turns a 30-second outage into an indefinite one, throwing into the host
     * on every request.
     */
    public function testACorruptCachedSnapshotIsDiscardedRatherThanThrowingForever(): void
    {
        $cache = $this->cache();
        $api = new FakeEvalApi();
        $this->client('k', $cache, $api)->boolVariation('kill-switch', ['user_id' => 'u'], false);

        // Corrupt the stored snapshot in place.
        $key = (new \ReflectionMethod(FlagStore::class, 'cacheKey'))
            ->invoke(new FlagStore($cache, md5('k'), 30), 'snapshot');
        $cache->set($key, ['flags' => [['no' => 'key']], 'segments' => []]);

        FeatureflipClient::resetForTesting();
        $client = $this->client('k', $cache, $api);

        $this->assertTrue($client->boolVariation('kill-switch', ['user_id' => 'u'], false), 'A corrupt snapshot must be discarded and refetched, not rethrown at the caller');
        $this->assertNull($cache->get($key . '_never'), 'sanity');
    }

    // --- Per-flag parse resilience -----------------------------------------

    public function testOneMalformedFlagDoesNotDiscardTheHealthyOnes(): void
    {
        $api = new FakeEvalApi();
        // A weighted variation with no `weight` — a field the models read unguarded.
        $api->extraFlags = [[
            'key' => 'broken', 'version' => 1, 'type' => 'boolean', 'enabled' => true,
            'variations' => [['key' => 'on', 'value' => true]], 'rules' => [],
            'fallthrough' => ['type' => 'Rollout', 'bucketBy' => 'userId', 'salt' => 's', 'variations' => [['key' => 'on']]],
            'offVariation' => null,
        ]];

        $client = $this->client('k', $this->cache(), $api);

        $this->assertTrue($client->boolVariation('kill-switch', ['user_id' => 'u'], false), 'A malformed sibling must not take the healthy flags with it');
    }

    public function testAMalformedFlagIsLogged(): void
    {
        $api = new FakeEvalApi();
        $api->extraFlags = [['version' => 1, 'enabled' => true]];   // no `key` at all
        $logger = new RecordingLogger();

        $this->client('k', $this->cache(), $api, $logger);

        $this->assertTrue($logger->contains('skipped'), "Dropping a flag silently is what made #2258 invisible; got: {$logger}");
    }

    /**
     * Rejecting a malformed entry must be a deliberate, described failure — not
     * a PHP warning escaping on the way to a TypeError. Under a framework error
     * handler that warning becomes an ErrorException, and on bare PHP it lands
     * in the host's error log on every single poll.
     */
    public function testRejectingAMalformedFlagRaisesNoPhpWarning(): void
    {
        $api = new FakeEvalApi();
        $api->extraFlags = [[
            'key' => 'broken', 'version' => 1, 'type' => 'boolean', 'enabled' => true,
            'variations' => [['key' => 'on', 'value' => true]], 'rules' => [],
            'fallthrough' => ['type' => 'Rollout', 'bucketBy' => 'userId', 'salt' => 's', 'variations' => [['key' => 'on']]],
            'offVariation' => null,
        ]];

        $raised = [];
        set_error_handler(static function (int $no, string $str) use (&$raised): bool {
            $raised[] = $str;
            return true;
        });

        try {
            $client = $this->client('k', $this->cache(), $api, new RecordingLogger());
        } finally {
            restore_error_handler();
        }

        $this->assertSame(
            [],
            array_values(array_filter($raised, static fn (string $m): bool => str_contains($m, 'Undefined array key'))),
            'A malformed flag must be rejected explicitly, not via a PHP warning',
        );
        $this->assertTrue($client->boolVariation('kill-switch', ['user_id' => 'u'], false));
    }

    /**
     * A 200 that yields nothing usable must not overwrite a good configuration.
     * Publishing an empty snapshot reaches the very outcome this issue is about
     * — every flag serving its caller default — through a successful response
     * rather than a failed one.
     *
     * @param array<string, mixed> $payload
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('uselessPayloads')]
    public function testASuccessfulButUselessResponseDoesNotDestroyGoodConfig(array $payload): void
    {
        $cache = $this->cache();
        $api = new FakeEvalApi();
        // pollInterval 0 leaves the snapshot due for refresh immediately, so
        // this needs no wall-clock wait — what is under test is that the
        // refresh refuses to publish, not when it fires.
        $this->client('k', $cache, $api, null, 0)->boolVariation('kill-switch', ['user_id' => 'u'], false);

        $api->payloadOverride = $payload;

        FeatureflipClient::resetForTesting();
        $client = $this->client('k', $cache, $api, new RecordingLogger(), 0);

        $this->assertTrue($client->boolVariation('kill-switch', ['user_id' => 'u'], false), 'Good configuration must survive a degraded response');
    }

    /** @return array<string, array{array<string, mixed>}> */
    public static function uselessPayloads(): array
    {
        return [
            'empty flag list' => [['flags' => [], 'segments' => []]],
            'no flags key' => [['ok' => true]],
            'every flag malformed' => [['flags' => [['no' => 'key'], ['also' => 'no key']], 'segments' => []]],
            'flags is not a list' => [['flags' => 'oops', 'segments' => []]],
        ];
    }

    public function testAnUnreadableFlagsPayloadIsLogged(): void
    {
        $api = new FakeEvalApi();
        $api->payloadOverride = ['flags' => 'oops', 'segments' => []];
        $logger = new RecordingLogger();

        $this->client('k', $this->cache(), $api, $logger);

        $this->assertTrue($logger->contains('not a list'), "An unusable payload shape must not pass silently; got: {$logger}");
    }

    public function testACacheThatRejectsWritesIsReportedRatherThanRefetchingForever(): void
    {
        $api = new FakeEvalApi();
        $cache = new ReadOnlyCache($this->cache());
        $logger = new RecordingLogger();

        for ($request = 0; $request < 5; $request++) {
            FeatureflipClient::resetForTesting();
            $this->client('k', $cache, $api, $logger)->boolVariation('kill-switch', ['user_id' => 'u'], false);
        }

        $this->assertTrue($logger->contains('cache'), "A cache that silently drops writes turns every request into a full refetch; got: {$logger}");
    }

    // --- Failures are surfaced ---------------------------------------------

    public function testAnUnreachableEvalApiIsLogged(): void
    {
        $api = new FakeEvalApi();
        $api->down = true;
        $logger = new RecordingLogger();

        $this->client('k', $this->cache(), $api, $logger);

        $this->assertTrue($logger->contains('unreachable'), "Expected the transport failure to be reported; got: {$logger}");
    }

    public function testAnUnauthorizedSdkKeyIsLogged(): void
    {
        $api = new FakeEvalApi();
        $api->status = 401;
        $logger = new RecordingLogger();

        $this->client('k', $this->cache(), $api, $logger);

        $this->assertTrue($logger->contains('401'), "A wrong SDK key is the likeliest first mistake and must not be silent; got: {$logger}");
    }

    public function testTheDefaultLoggerWritesToTheErrorLog(): void
    {
        $api = new FakeEvalApi();
        $api->down = true;

        $file = tempnam(sys_get_temp_dir(), 'ff-');
        $previous = ini_set('error_log', $file);

        try {
            $factory = new HttpFactory();
            FeatureflipClient::get('k', new Config(
                baseUrl: 'http://eval.test',
                pollInterval: 1,
                cache: $this->cache(),
                httpClient: $api->withFactory($factory),
                requestFactory: $factory,
                streamFactory: $factory,
            ));   // no logger at all — the SDK must fall back to error_log
        } finally {
            ini_set('error_log', (string) $previous);
        }

        $contents = (string) file_get_contents($file);
        unlink($file);

        $this->assertStringContainsString('[featureflip]', $contents);
    }

    // --- Backoff -----------------------------------------------------------

    public function testAFailedFetchIsNotRetriedOnEveryRequest(): void
    {
        $cache = $this->cache();
        $api = new FakeEvalApi();
        $api->down = true;

        for ($request = 0; $request < 5; $request++) {
            FeatureflipClient::resetForTesting();
            $this->client('k', $cache, $api)->boolVariation('kill-switch', ['user_id' => 'u'], false);
        }

        $this->assertSame(1, $api->fetches, 'A down eval API must not be re-dialled inline on every single request');
    }

    // --- Atomic snapshot ---------------------------------------------------

    public function testAConcurrentWriterCannotProduceATornRead(): void
    {
        $inner = $this->cache();
        $cache = new RacyCache($inner);

        (new FlagStore($cache, 'hash', 300))->putAll([$this->flag('beta-v1')], [new Segment('beta-v1', 1, [], 'and')]);

        // A second worker republishes mid-read, between this store's cache reads.
        $cache->onGet = function () use ($cache): void {
            $cache->onGet = null;
            (new FlagStore($cache, 'hash', 300))->putAll([$this->flag('beta-v2')], [new Segment('beta-v2', 2, [], 'and')]);
        };

        $store = new FlagStore($cache, 'hash', 300);
        $segmentKey = $store->getFlag('kill-switch')?->rules[0]->segmentKey;

        $this->assertContains(
            $segmentKey,
            array_keys($store->getSegments()),
            'Flags and segments must come from the same generation — a rule pointing at a segment that is not there silently stops matching',
        );
    }

    // --- Fixtures ----------------------------------------------------------

    private function cache(): Psr16Cache
    {
        return new Psr16Cache(new ArrayAdapter());
    }

    private function flag(string $segmentKey = 'beta-v1'): Flag
    {
        return new Flag(
            key: 'kill-switch',
            version: 1,
            type: 'boolean',
            enabled: true,
            variations: [new Variation('on', true), new Variation('off', false)],
            rules: [new \Featureflip\Model\Rule('r1', 0, [], new ServeConfig('Fixed', 'on', null, null, null), $segmentKey)],
            fallthrough: new ServeConfig('Fixed', 'on', null, null, null),
            offVariation: 'off',
        );
    }

    private function client(string $key, \Psr\SimpleCache\CacheInterface $cache, FakeEvalApi $api, ?LoggerInterface $logger = null, int $pollInterval = 1): FeatureflipClient
    {
        $factory = new HttpFactory();

        return FeatureflipClient::get($key, new Config(
            baseUrl: 'http://eval.test',
            pollInterval: $pollInterval,
            cache: $cache,
            httpClient: $api->withFactory($factory),
            requestFactory: $factory,
            streamFactory: $factory,
            logger: $logger ?? new NullLogger(),
        ));
    }
}

/** A PSR-18 client standing in for the evaluation API. */
final class FakeEvalApi implements ClientInterface
{
    public bool $down = false;
    public int $status = 200;
    public int $fetches = 0;
    /** @var array<int, array<string, mixed>> */
    public array $extraFlags = [];
    /** @var array<string, mixed>|null */
    public ?array $payloadOverride = null;
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
        if ($this->status >= 400) {
            return $this->factory->createResponse($this->status)->withBody($this->factory->createStream('{"error":"Invalid SDK key"}'));
        }

        $body = json_encode($this->payloadOverride ?? [
            'flags' => [[
                'key' => 'kill-switch', 'version' => 1, 'type' => 'boolean', 'enabled' => true,
                'variations' => [['key' => 'on', 'value' => true], ['key' => 'off', 'value' => false]],
                'rules' => [], 'fallthrough' => ['type' => 'Fixed', 'variation' => 'on'], 'offVariation' => 'off',
            ], ...$this->extraFlags],
            'segments' => [],
        ]);

        return $this->factory->createResponse(200)->withBody($this->factory->createStream((string) $body));
    }
}

/** Captures everything the SDK logs so a test can assert it said something. */
final class RecordingLogger extends AbstractLogger
{
    /** @var list<string> */
    public array $lines = [];

    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $this->lines[] = strtolower($level . ' ' . $message . ' ' . json_encode($context));
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

/** A cache that accepts reads but silently drops every write. */
final class ReadOnlyCache implements \Psr\SimpleCache\CacheInterface
{
    public function __construct(private \Psr\SimpleCache\CacheInterface $inner) {}

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->inner->get($key, $default);
    }

    public function set(string $key, mixed $value, null|int|\DateInterval $ttl = null): bool
    {
        return false;
    }

    public function delete(string $key): bool
    {
        return $this->inner->delete($key);
    }

    public function clear(): bool
    {
        return $this->inner->clear();
    }

    public function getMultiple(iterable $keys, mixed $default = null): iterable
    {
        return $this->inner->getMultiple($keys, $default);
    }

    public function setMultiple(iterable $values, null|int|\DateInterval $ttl = null): bool
    {
        return false;
    }

    public function deleteMultiple(iterable $keys): bool
    {
        return $this->inner->deleteMultiple($keys);
    }

    public function has(string $key): bool
    {
        return $this->inner->has($key);
    }
}

/** Lets a test interleave a competing writer between a store's cache reads. */
final class RacyCache implements \Psr\SimpleCache\CacheInterface
{
    /** @var null|callable */
    public $onGet = null;

    public function __construct(private \Psr\SimpleCache\CacheInterface $inner) {}

    public function get(string $key, mixed $default = null): mixed
    {
        $value = $this->inner->get($key, $default);
        if ($this->onGet !== null) {
            ($this->onGet)($key);
        }
        return $value;
    }

    public function set(string $key, mixed $value, null|int|\DateInterval $ttl = null): bool
    {
        return $this->inner->set($key, $value, $ttl);
    }

    public function delete(string $key): bool
    {
        return $this->inner->delete($key);
    }

    public function clear(): bool
    {
        return $this->inner->clear();
    }

    public function getMultiple(iterable $keys, mixed $default = null): iterable
    {
        return $this->inner->getMultiple($keys, $default);
    }

    public function setMultiple(iterable $values, null|int|\DateInterval $ttl = null): bool
    {
        return $this->inner->setMultiple($values, $ttl);
    }

    public function deleteMultiple(iterable $keys): bool
    {
        return $this->inner->deleteMultiple($keys);
    }

    public function has(string $key): bool
    {
        return $this->inner->has($key);
    }
}
