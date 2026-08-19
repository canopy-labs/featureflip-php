<?php

declare(strict_types=1);

namespace Featureflip\Tests;

use Featureflip\{Config, FeatureflipClient};
use GuzzleHttp\Psr7\HttpFactory;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\{RequestInterface, ResponseInterface};
use Psr\Log\{LoggerInterface, NullLogger};
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Psr16Cache;

/**
 * Behaviour under a persistent worker — Laravel Octane, RoadRunner, FrankenPHP,
 * Swoole (#2260).
 *
 * The SDK's lifecycle assumed PHP-FPM, where every request builds a new core and
 * the staleness check therefore runs every request. A worker builds the core
 * once at boot and keeps it for thousands of requests, so that check never ran
 * again: flags froze at boot until the process restarted, and the event queue
 * grew without bound because nothing drained it before shutdown.
 */
final class LongRunningRuntimeTest extends TestCase
{
    protected function tearDown(): void
    {
        FeatureflipClient::resetForTesting();
    }

    // --- Flags refresh inside a living process -----------------------------

    public function testAWorkerPicksUpAFlagChangeAfterThePollInterval(): void
    {
        $api = new MutableEvalApi();
        $client = $this->client($api, pollInterval: 1);

        $this->assertTrue($client->boolVariation('new-checkout', ['user_id' => 'u'], false));

        // Someone turns the flag off in the dashboard.
        $api->serve = 'off';
        sleep(2);

        // Same client, same process — no reconstruction.
        $this->assertFalse(
            $client->boolVariation('new-checkout', ['user_id' => 'u'], true),
            'A persistent worker must see the change without being restarted',
        );
    }

    public function testAFreshConfigurationIsNotRefetchedOnEveryEvaluation(): void
    {
        $api = new MutableEvalApi();
        $client = $this->client($api, pollInterval: 300);

        for ($i = 0; $i < 50; $i++) {
            $client->boolVariation('new-checkout', ['user_id' => "u$i"], false);
        }

        $this->assertSame(1, $api->fetches, 'The staleness check must be a time comparison, not a fetch');
    }

    public function testAnUnreachableApiIsNotRedialledOnEveryEvaluation(): void
    {
        $api = new MutableEvalApi();
        $client = $this->client($api, pollInterval: 0);   // always due a refresh
        $api->down = true;

        for ($i = 0; $i < 20; $i++) {
            $client->boolVariation('new-checkout', ['user_id' => "u$i"], false);
        }

        $this->assertLessThanOrEqual(2, $api->fetches, 'The backoff must hold on the evaluation path too, not just at construction');
    }

    /**
     * The staleness check runs on every variation call, so it must not consult
     * the shared cache on every variation call. When the configuration is fresh
     * the `||` short-circuit keeps it to an in-memory integer comparison — but
     * while a failed refresh is backing off the store stays stale, so a naive
     * check would read the backoff marker out of Redis once per evaluation, for
     * the whole outage.
     */
    public function testTheEvaluationPathDoesNotReadTheSharedCachePerEvaluation(): void
    {
        $api = new MutableEvalApi();
        $cache = new CountingCache(new Psr16Cache(new ArrayAdapter()));

        $factory = new HttpFactory();
        $client = FeatureflipClient::get('counting-key', new Config(
            baseUrl: 'http://eval.test',
            pollInterval: 0,        // always due a refresh
            flushInterval: 100000,
            flushBatchSize: 100000,
            cache: $cache,
            httpClient: $api->withFactory($factory),
            requestFactory: $factory,
            streamFactory: $factory,
            logger: new NullLogger(),
        ));

        $api->down = true;
        $client->boolVariation('new-checkout', ['user_id' => 'x'], false);   // trip the backoff

        $cache->reads = 0;
        for ($i = 0; $i < 500; $i++) {
            $client->boolVariation('new-checkout', ['user_id' => "u$i"], false);
        }

        $this->assertLessThanOrEqual(
            5,
            $cache->reads,
            'An outage must not turn every flag check into a cache round-trip',
        );
    }

    // --- The explicit escape hatch -----------------------------------------

    public function testRefreshPicksUpAChangeWithoutEvaluatingAnything(): void
    {
        // A generous poll interval on purpose: time() has one-second
        // granularity, so a 1s window can be crossed by construction alone and
        // the exact-fetch-count assertion below would flake.
        $api = new MutableEvalApi();
        $client = $this->client($api, pollInterval: 3);
        $this->assertTrue($client->boolVariation('new-checkout', ['user_id' => 'u'], false));

        $api->serve = 'off';
        sleep(4);

        // What an Octane tick listener or RoadRunner worker loop would call,
        // so the fetch lands between requests rather than inside one.
        $client->refresh();

        $this->assertSame(2, $api->fetches);
        $this->assertFalse($client->boolVariation('new-checkout', ['user_id' => 'u'], true));
    }

    public function testRefreshIsCheapToCallInATickLoop(): void
    {
        $api = new MutableEvalApi();
        $client = $this->client($api, pollInterval: 300);

        for ($i = 0; $i < 50; $i++) {
            $client->refresh();
        }

        $this->assertSame(1, $api->fetches, 'refresh() must honour the poll interval so a tick handler can call it freely');
    }

    // --- Events drain without waiting for shutdown -------------------------

    public function testTheQueueFlushesOnceItReachesTheBatchSize(): void
    {
        $api = new MutableEvalApi();
        $client = $this->client($api, pollInterval: 300, flushBatchSize: 10);

        for ($i = 0; $i < 25; $i++) {
            $client->boolVariation('new-checkout', ['user_id' => "u$i"], false);
        }

        $this->assertGreaterThanOrEqual(2, $api->posts, 'A worker must not accumulate events until it dies');
    }

    public function testTheQueueFlushesOnceTheFlushIntervalElapses(): void
    {
        $api = new MutableEvalApi();
        $client = $this->client($api, pollInterval: 300, flushBatchSize: 1000, flushInterval: 1);

        $client->boolVariation('new-checkout', ['user_id' => 'u'], false);
        $this->assertSame(0, $api->posts, 'Nothing is due yet');

        sleep(2);
        $client->boolVariation('new-checkout', ['user_id' => 'u'], false);

        $this->assertSame(1, $api->posts, 'A quiet worker must still ship its events');
    }

    public function testEventsAreNotShippedBeforeEitherThresholdIsReached(): void
    {
        $api = new MutableEvalApi();
        $client = $this->client($api, pollInterval: 300, flushBatchSize: 1000, flushInterval: 300);

        for ($i = 0; $i < 20; $i++) {
            $client->boolVariation('new-checkout', ['user_id' => "u$i"], false);
        }

        $this->assertSame(0, $api->posts, 'Batching still matters — do not post one request per evaluation');
    }

    public function testTheQueueDoesNotGrowWithoutBound(): void
    {
        $api = new MutableEvalApi();
        $client = $this->client($api, pollInterval: 300, flushBatchSize: 50);

        for ($i = 0; $i < 5000; $i++) {
            $client->boolVariation('new-checkout', ['user_id' => "u$i"], false);
        }

        $this->assertLessThanOrEqual(50, $this->queueSize($client), 'The queue is a memory leak in a worker if nothing caps it');
    }

    // --- Nothing the evaluation path now does may escape or run away -------

    /**
     * The refresh happens inline on the evaluation path, so a PSR-18 client
     * that itself evaluates a flag — a Guzzle middleware gating a retry, say —
     * re-enters evaluateFlag() while the store is still stale and the refresh
     * has not yet recorded that it is in flight.
     */
    public function testAFlagAwareHttpClientCannotRecurse(): void
    {
        $api = new ReentrantEvalApi();
        $client = $this->client($api, pollInterval: 0);
        $api->onFetch = function () use ($client): void {
            $client->boolVariation('new-checkout', ['user_id' => 're-entrant'], false);
        };

        $client->boolVariation('new-checkout', ['user_id' => 'u'], false);

        $this->assertLessThanOrEqual(3, $api->fetches, 'A re-entrant evaluation must not trigger another refresh');
    }

    public function testAThrowingLoggerDoesNotEscapeIntoTheHost(): void
    {
        $api = new MutableEvalApi();
        $api->down = true;
        $client = $this->client($api, pollInterval: 0, logger: new ThrowingLogger());

        $this->assertFalse(
            $client->boolVariation('new-checkout', ['user_id' => 'u'], false),
            'A logger that throws — Monolog on a full disk — must not fail the caller request',
        );
    }

    /**
     * #2264 refuses to publish a response that carries no usable flags, so the
     * store stays stale — which, now that staleness drives the evaluation path,
     * would mean a fetch and a log line for every single variation call.
     */
    public function testARefusedPublishDoesNotRefetchOnEveryEvaluation(): void
    {
        $api = new MutableEvalApi();
        $logger = new RecordingLogger();
        $client = $this->client($api, pollInterval: 0, logger: $logger);
        $client->boolVariation('new-checkout', ['user_id' => 'u'], false);

        $api->serveNoFlags = true;
        $api->fetches = 0;
        for ($i = 0; $i < 100; $i++) {
            $client->boolVariation('new-checkout', ['user_id' => "u$i"], false);
        }

        $this->assertLessThanOrEqual(3, $api->fetches, 'A degraded-but-successful response must not become a per-evaluation fetch loop');
        $this->assertLessThanOrEqual(3, count($logger->lines), 'Nor a per-evaluation log line');
    }

    public function testASingleEventBatchSizeDoesNotRunAway(): void
    {
        $api = new ReentrantEvalApi();
        $client = $this->client($api, pollInterval: 300, flushBatchSize: 1);
        $api->onPost = function () use ($client): void {
            $client->boolVariation('new-checkout', ['user_id' => 'from-post'], false);
        };

        $client->boolVariation('new-checkout', ['user_id' => 'u'], false);

        $this->assertLessThanOrEqual(5, $api->posts, 'Flushing during a flush must not recurse');
    }

    /**
     * A zero poll interval was used directly as the backoff duration, and
     * PSR-16 treats a zero TTL as "already expired" — so asking to always
     * refresh also meant no backoff at all.
     */
    public function testAZeroPollIntervalStillBacksOffAfterAFailure(): void
    {
        $api = new MutableEvalApi();
        $api->down = true;
        $cache = new Psr16Cache(new ArrayAdapter());

        $factory = new HttpFactory();
        $config = fn () => new Config(
            baseUrl: 'http://eval.test', pollInterval: 0,
            flushInterval: 100000, flushBatchSize: 100000, cache: $cache,
            httpClient: $api->withFactory($factory), requestFactory: $factory,
            streamFactory: $factory, logger: new NullLogger(),
        );

        // Separate processes sharing one cache: the marker must survive, so the
        // second one does not re-dial an API the first just found unreachable.
        FeatureflipClient::get('floor-key', $config());
        FeatureflipClient::resetForTesting();
        $api->fetches = 0;
        FeatureflipClient::get('floor-key', $config());

        $this->assertSame(0, $api->fetches, 'The backoff marker must outlive a zero poll interval');
    }

    // --- Batching still batches --------------------------------------------

    public function testAQuietWorkerStillBatchesRatherThanPostingEachEvent(): void
    {
        $api = new MutableEvalApi();
        $client = $this->client($api, pollInterval: 300, flushBatchSize: 1000, flushInterval: 1);

        // Four events, each arriving after the interval has already elapsed.
        for ($i = 0; $i < 4; $i++) {
            sleep(2);
            $client->boolVariation('new-checkout', ['user_id' => "u$i"], false);
        }

        $this->assertLessThanOrEqual(
            2,
            $api->posts,
            'The interval must age the oldest queued event, not the last flush — otherwise every push posts',
        );
    }

    public function testAZeroFlushIntervalDoesNotPostPerEvaluation(): void
    {
        $api = new MutableEvalApi();
        $client = $this->client($api, pollInterval: 300, flushBatchSize: 1000, flushInterval: 0);

        for ($i = 0; $i < 20; $i++) {
            $client->boolVariation('new-checkout', ['user_id' => "u$i"], false);
        }

        $this->assertLessThanOrEqual(3, $api->posts, 'A zero interval must be floored, like the poll interval already is');
    }

    // --- Shutdown is idempotent --------------------------------------------

    public function testTheCoreOnlyShutsDownOnce(): void
    {
        $api = new MutableEvalApi();
        $client = $this->client($api, pollInterval: 300, flushBatchSize: 1000, flushInterval: 300);
        $client->boolVariation('new-checkout', ['user_id' => 'u'], false);

        $core = (new \ReflectionProperty(FeatureflipClient::class, 'core'))->getValue($client);
        $core->shutdown();
        $core->shutdown();

        $this->assertSame(1, $api->posts, 'The guard the fastcgi_finish_request gating depends on must actually hold');
    }

    public function testRefreshOnAClosedClientDoesNothing(): void
    {
        $api = new MutableEvalApi();
        $client = $this->client($api, pollInterval: 0);
        $client->close();

        $api->fetches = 0;
        $client->refresh();

        $this->assertSame(0, $api->fetches, 'A closed handle must not reach out to the network');
    }

    public function testShutdownIsIdempotent(): void
    {
        $api = new MutableEvalApi();
        $client = $this->client($api, pollInterval: 300, flushBatchSize: 1000, flushInterval: 300);
        $client->boolVariation('new-checkout', ['user_id' => 'u'], false);

        $client->close();
        $postsAfterFirstClose = $api->posts;
        $client->close();

        $this->assertSame(1, $postsAfterFirstClose, 'Closing flushes what is queued');
        $this->assertSame($postsAfterFirstClose, $api->posts, 'Closing again must do nothing at all');
    }

    // --- Fixtures ----------------------------------------------------------

    private function queueSize(FeatureflipClient $client): int
    {
        $core = (new \ReflectionProperty(FeatureflipClient::class, 'core'))->getValue($client);
        $processor = (new \ReflectionProperty($core::class, 'eventProcessor'))->getValue($core);

        return $processor->queueSize();
    }

    private function client(
        MutableEvalApi $api,
        int $pollInterval = 30,
        int $flushBatchSize = 1000,
        int $flushInterval = 300,
        ?LoggerInterface $logger = null,
    ): FeatureflipClient {
        $factory = new HttpFactory();

        return FeatureflipClient::get('worker-key', new Config(
            baseUrl: 'http://eval.test',
            pollInterval: $pollInterval,
            flushInterval: $flushInterval,
            flushBatchSize: $flushBatchSize,
            cache: new Psr16Cache(new ArrayAdapter()),
            httpClient: $api->withFactory($factory),
            requestFactory: $factory,
            streamFactory: $factory,
            logger: $logger ?? new NullLogger(),
        ));
    }
}

/** An evaluation API whose answer can change between fetches. */
class MutableEvalApi implements ClientInterface
{
    public string $serve = 'on';
    public bool $down = false;
    public bool $serveNoFlags = false;
    public int $fetches = 0;
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

        $this->fetches++;
        if ($this->down) {
            throw new \RuntimeException('eval api unreachable');
        }

        if ($this->serveNoFlags) {
            return $this->factory->createResponse(200)
                ->withBody($this->factory->createStream('{"flags":[],"segments":[]}'));
        }

        $body = json_encode([
            'flags' => [[
                'key' => 'new-checkout', 'version' => 1, 'type' => 'boolean', 'enabled' => true,
                'variations' => [['key' => 'on', 'value' => true], ['key' => 'off', 'value' => false]],
                'rules' => [], 'fallthrough' => ['type' => 'Fixed', 'variation' => $this->serve],
                'offVariation' => 'off',
            ]],
            'segments' => [],
        ]);

        return $this->factory->createResponse(200)->withBody($this->factory->createStream((string) $body));
    }
}

/** Counts reads, so a test can assert the hot path stays off the shared cache. */
final class CountingCache implements \Psr\SimpleCache\CacheInterface
{
    public int $reads = 0;

    public function __construct(private \Psr\SimpleCache\CacheInterface $inner) {}

    public function get(string $key, mixed $default = null): mixed
    {
        $this->reads++;

        return $this->inner->get($key, $default);
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

/** Lets a test drive the SDK from inside the PSR-18 client, as a middleware would. */
final class ReentrantEvalApi extends MutableEvalApi
{
    /** @var null|callable */
    public $onFetch = null;

    /** @var null|callable */
    public $onPost = null;

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $hook = $request->getMethod() === 'POST' ? $this->onPost : $this->onFetch;
        $response = parent::sendRequest($request);

        if ($hook !== null) {
            $hook();
        }

        return $response;
    }
}

/** A PSR-3 logger that fails, the way Monolog does on a full disk. */
final class ThrowingLogger extends \Psr\Log\AbstractLogger
{
    public function log($level, string|\Stringable $message, array $context = []): void
    {
        throw new \RuntimeException('log sink unavailable');
    }
}
