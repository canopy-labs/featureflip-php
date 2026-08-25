<?php

declare(strict_types=1);

namespace Featureflip\Tests\Events;

use Featureflip\Events\{Event, EventProcessor};
use Featureflip\Http\HttpClient;
use GuzzleHttp\Psr7\HttpFactory;
use Psr\Http\Client\{ClientExceptionInterface, ClientInterface};
use Psr\Http\Message\{RequestInterface, ResponseInterface};
use Psr\Log\AbstractLogger;
use PHPUnit\Framework\TestCase;

/**
 * What happens to queued analytics when the events endpoint refuses them (#2456).
 *
 * `flush()` chunked and cleared the queue before posting anything, so a batch the
 * endpoint rejected was already gone by the time the failure was known — and the
 * production edge answers this endpoint with a 503 at a low but constant rate, so
 * analytics were being lost steadily. Worse, `HttpClient::post()` reported success
 * for every response it received, so that 503 was counted as a delivery: the
 * `dropped N` warning added by #2258 could not fire for it at all.
 *
 * The contract, shared with every other SDK: a retryable failure (5xx, 429, any
 * transport fault) puts the batch back at the FRONT of the queue for the NEXT
 * flush; anything else is dropped with a warning; the queue is bounded and sheds
 * oldest-first; and none of it is ever silent.
 */
final class EventDeliveryResilienceTest extends TestCase
{
    private CapturingLogger $logger;

    protected function setUp(): void
    {
        $this->logger = new CapturingLogger();
    }

    // --- Retryable failures keep the batch ---------------------------------

    public function testA503KeepsTheBatchSoTheNextFlushResendsTheSameEvents(): void
    {
        $api = new EventsEndpoint(503);
        $processor = $this->processor($api);

        $processor->push(Event::evaluation('flag-a', ['user_id' => 'u1'], 'on'));
        $processor->push(Event::evaluation('flag-b', ['user_id' => 'u2'], 'off'));
        $processor->flush();

        $this->assertSame(1, $api->posts, 'The batch was attempted');
        $this->assertSame(2, $processor->queueSize(), 'A 503 is transient — the events must survive it');

        $api->status = 200;
        $processor->flush();

        $this->assertSame(2, $api->posts);
        $this->assertSame(
            ['flag-a', 'flag-b'],
            $api->flagKeysOfPost(1),
            'The next flush must re-send exactly what the 503 rejected, in order',
        );
        $this->assertSame(0, $processor->queueSize());
    }

    public function testA503IsReportedRatherThanCountedAsDelivered(): void
    {
        $processor = $this->processor(new EventsEndpoint(503));

        $processor->push(Event::evaluation('flag-a', ['user_id' => 'u1'], 'on'));
        $processor->flush();

        $this->assertTrue(
            $this->logger->contains('503'),
            "A rejected batch must say so — the endpoint's status is the whole diagnosis. Logged: {$this->logger}",
        );
        $this->assertTrue(
            $this->logger->contains('analytics event'),
            "The #2258 reporting must still fire. Logged: {$this->logger}",
        );
    }

    public function testATransportFaultKeepsTheBatchToo(): void
    {
        $api = new EventsEndpoint(200, new TransportFault('connection reset by peer'));
        $processor = $this->processor($api);

        $processor->push(Event::evaluation('flag-a', ['user_id' => 'u1'], 'on'));
        $processor->flush();

        $this->assertSame(1, $processor->queueSize(), 'A refused connection is as transient as a 503');

        $api->fault = null;
        $processor->flush();

        $this->assertSame(['flag-a'], $api->flagKeysOfPost(1));
        $this->assertSame(0, $processor->queueSize());
    }

    /**
     * Retrying belongs to the NEXT flush, not this one: a batch put back at the
     * front of the queue the loop is draining would otherwise be re-sent
     * immediately, spinning for as long as the endpoint stayed down.
     */
    public function testAFailedBatchIsNotRetriedInsideTheSameFlush(): void
    {
        $api = new EventsEndpoint(503);
        $processor = $this->processor($api, batchSize: 2);

        // The first pair trips the size trigger and fails, which puts the size
        // trigger in backoff — so the remaining four just accumulate behind it.
        for ($i = 0; $i < 6; $i++) {
            $processor->push(Event::evaluation("flag-$i", ['user_id' => 'u'], 'on'));
        }
        $this->assertSame(6, $processor->queueSize());

        $attemptsBefore = $api->posts;
        $processor->flush();

        $this->assertSame(
            $attemptsBefore + 1,
            $api->posts,
            'Three batches are queued, but a flush that fails stops at the first',
        );
        $this->assertSame(6, $processor->queueSize(), 'The un-sent batches are held back with the failed one');
    }

    // --- Permanent failures drop the batch ---------------------------------

    public function testARejectedSdkKeyDropsTheBatchWithoutRetrying(): void
    {
        $api = new EventsEndpoint(401);
        $processor = $this->processor($api);

        $processor->push(Event::evaluation('flag-a', ['user_id' => 'u1'], 'on'));
        $processor->flush();

        $this->assertSame(0, $processor->queueSize(), 'A rejected key will reject the same batch forever');
        $this->assertTrue(
            $this->logger->contains('dropped 1 analytics event'),
            "The drop must be reported. Logged: {$this->logger}",
        );
        $this->assertTrue(
            $this->logger->contains('non-retryable'),
            "…and say why it was not kept. Logged: {$this->logger}",
        );

        $processor->flush();
        $this->assertSame(1, $api->posts, 'Nothing is left to send');
    }

    /**
     * A permanent rejection is scoped to the batch that earned it: the flush
     * carries on to the batches behind it rather than abandoning them.
     */
    public function testAPermanentlyRejectedBatchDoesNotStopTheOnesBehindIt(): void
    {
        // Queue three batches' worth behind an outage, then have the endpoint
        // start rejecting them permanently.
        $api = new EventsEndpoint(503);
        $processor = $this->processor($api, batchSize: 2);

        for ($i = 0; $i < 6; $i++) {
            $processor->push(Event::evaluation("flag-$i", ['user_id' => 'u'], 'on'));
        }
        $this->assertSame(6, $processor->queueSize());

        $api->status = 400;
        $attemptsBefore = $api->posts;
        $processor->flush();

        $this->assertSame($attemptsBefore + 3, $api->posts, 'Every batch is offered — the drop is scoped to one');
        $this->assertSame(0, $processor->queueSize());
        $this->assertTrue(
            $this->logger->contains('dropped 6 analytics event'),
            "One line per flush, carrying the whole count. Logged: {$this->logger}",
        );
    }

    // --- The queue is bounded ----------------------------------------------

    public function testOverflowShedsTheOldestEvents(): void
    {
        $api = new EventsEndpoint(200);
        $processor = $this->processor($api, batchSize: 100, maxQueueSize: 5);

        for ($i = 0; $i < 8; $i++) {
            $processor->push(Event::evaluation("flag-$i", ['user_id' => 'u'], 'on'));
        }

        $this->assertSame(5, $processor->queueSize(), 'The bound is an upper bound, not a suggestion');
        $this->assertSame(
            3,
            $this->logger->countContaining('of the oldest analytics event'),
            "Shedding must report how much it shed, every time. Logged: {$this->logger}",
        );

        $processor->flush();

        $this->assertSame(
            ['flag-3', 'flag-4', 'flag-5', 'flag-6', 'flag-7'],
            $api->flagKeysOfPost(0),
            'The freshest analytics are the ones worth keeping',
        );
    }

    public function testAnOutageCannotGrowTheQueuePastTheBound(): void
    {
        $api = new EventsEndpoint(503);
        $processor = $this->processor($api, batchSize: 4, flushInterval: 1, maxQueueSize: 6);

        for ($i = 0; $i < 40; $i++) {
            $processor->push(Event::evaluation("flag-$i", ['user_id' => 'u'], 'on'));
            $this->assertLessThanOrEqual(6, $processor->queueSize());
        }

        $this->assertSame(6, $processor->queueSize());
    }

    // --- A failing endpoint is not hammered --------------------------------

    /**
     * A re-queued batch leaves the queue at or above the batch size, so the size
     * trigger in push() would fire on every subsequent event — turning a failing
     * endpoint into one request per evaluation, which is worse for the server
     * than the dropping this fix replaces.
     */
    public function testAFailingEndpointGetsOneRequestNotOnePerEvent(): void
    {
        $api = new EventsEndpoint(503);
        $processor = $this->processor($api, batchSize: 1, flushInterval: 3600);

        for ($i = 0; $i < 10; $i++) {
            $processor->push(Event::evaluation("flag-$i", ['user_id' => 'u'], 'on'));
        }

        $this->assertSame(1, $api->posts, 'The size trigger must back off after a retryable failure');
    }

    /** The interval trigger is the retry vehicle, so the gate must not suppress it. */
    public function testTheIntervalTriggerStillRetriesOnceTheBackoffElapses(): void
    {
        $api = new EventsEndpoint(503);
        $processor = $this->processor($api, batchSize: 100, flushInterval: 1);

        $processor->push(Event::evaluation('flag-a', ['user_id' => 'u'], 'on'));
        $processor->flush();
        $this->assertSame(1, $api->posts);

        $processor->push(Event::evaluation('flag-b', ['user_id' => 'u'], 'on'));
        $this->assertSame(1, $api->posts, 'Still inside the backoff window');

        sleep(2);
        $api->status = 200;
        $processor->push(Event::evaluation('flag-c', ['user_id' => 'u'], 'on'));

        $this->assertSame(2, $api->posts, 'A retryable failure must not park the queue forever');
        $this->assertSame(0, $processor->queueSize(), 'Everything held through the outage is delivered');
        $this->assertSame(['flag-a', 'flag-b', 'flag-c'], $api->flagKeysOfPost(1));
    }

    /** A delivered batch clears the backoff, so the size trigger works again. */
    public function testTheSizeTriggerResumesAfterASuccessfulSend(): void
    {
        $api = new EventsEndpoint(200);
        $processor = $this->processor($api, batchSize: 1, flushInterval: 3600);

        for ($i = 0; $i < 3; $i++) {
            $processor->push(Event::evaluation("flag-$i", ['user_id' => 'u'], 'on'));
        }

        $this->assertSame(3, $api->posts);
    }

    // --- Shutdown -----------------------------------------------------------

    public function testTheFinalFlushMakesOneAttemptAndDiscardsTheRemainder(): void
    {
        $api = new EventsEndpoint(503);
        $processor = $this->processor($api, batchSize: 100);

        $processor->push(Event::evaluation('flag-a', ['user_id' => 'u'], 'on'));
        $processor->close();

        $this->assertSame(1, $api->posts, 'One last attempt');
        $this->assertSame(0, $processor->queueSize(), 'Nothing will ever flush again, so nothing is kept');

        $processor->push(Event::evaluation('flag-b', ['user_id' => 'u'], 'on'));
        $processor->close();

        $this->assertSame(1, $api->posts, 'A closed processor is inert');
        $this->assertSame(0, $processor->queueSize());
    }

    // --- Fixtures -----------------------------------------------------------

    private function processor(
        EventsEndpoint $api,
        int $batchSize = 100,
        int $flushInterval = 3600,
        int $maxQueueSize = 10000,
    ): EventProcessor {
        $factory = new HttpFactory();
        $httpClient = new HttpClient(
            $api->withFactory($factory),
            $factory,
            $factory,
            'sdk-key',
            'http://eval.test',
            $this->logger,
        );

        return new EventProcessor($httpClient, $batchSize, $this->logger, $flushInterval, $maxQueueSize);
    }
}

/** An events endpoint that records what it was sent and can be made to fail. */
final class EventsEndpoint implements ClientInterface
{
    public int $posts = 0;

    /** @var list<array<string, mixed>> The decoded body of each POST, in order. */
    public array $bodies = [];

    private HttpFactory $factory;

    public function __construct(public int $status = 200, public ?\Throwable $fault = null) {}

    public function withFactory(HttpFactory $factory): self
    {
        $this->factory = $factory;
        return $this;
    }

    /** @return list<string> */
    public function flagKeysOfPost(int $index): array
    {
        return array_map(
            static fn (array $event): string => (string) $event['flagKey'],
            $this->bodies[$index]['events'] ?? [],
        );
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->posts++;
        /** @var array<string, mixed> $body */
        $body = json_decode((string) $request->getBody(), true) ?? [];
        $this->bodies[] = $body;

        if ($this->fault !== null) {
            throw $this->fault;
        }

        return $this->factory->createResponse($this->status)->withBody($this->factory->createStream('{}'));
    }
}

/** What a PSR-18 client raises when the request never produced a response. */
final class TransportFault extends \RuntimeException implements ClientExceptionInterface {}

/** Captures everything the processor logs so a test can assert it said something. */
final class CapturingLogger extends AbstractLogger
{
    /** @var list<string> */
    public array $lines = [];

    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $this->lines[] = strtolower($level . ' ' . $message . ' ' . json_encode($context));
    }

    public function contains(string $needle): bool
    {
        return $this->countContaining($needle) > 0;
    }

    public function countContaining(string $needle): int
    {
        $count = 0;
        foreach ($this->lines as $line) {
            if (str_contains($line, strtolower($needle))) {
                $count++;
            }
        }

        return $count;
    }

    public function __toString(): string
    {
        return $this->lines === [] ? '(nothing logged)' : implode(' | ', $this->lines);
    }
}
