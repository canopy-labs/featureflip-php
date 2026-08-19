<?php

declare(strict_types=1);

namespace Featureflip\Events;

use Featureflip\Http\HttpClient;
use Featureflip\Logging\ErrorLogLogger;
use Psr\Log\LoggerInterface;

/**
 * @internal Not part of the public API.
 */
final class EventProcessor
{
    /** @var Event[] */
    private array $queue = [];

    private LoggerInterface $logger;

    private readonly int $flushInterval;

    /**
     * Unix time the oldest currently-queued event arrived.
     *
     * Deliberately not "time of the last flush": a worker whose events arrive
     * further apart than the interval would then satisfy the check on every
     * single push and post them one at a time — defeating batching for exactly
     * the quiet worker the interval trigger exists to serve.
     */
    private ?int $oldestQueuedAt = null;

    /** Guards against a flush re-entering itself. */
    private bool $isFlushing = false;

    public function __construct(
        private readonly HttpClient $httpClient,
        private readonly int $batchSize,
        ?LoggerInterface $logger = null,
        int $flushInterval = 30,
    ) {
        $this->logger = $logger ?? new ErrorLogLogger();
        // Floored for the same reason the refresh backoff is: asking for zero
        // is not asking for one HTTP POST per evaluation.
        $this->flushInterval = max(1, $flushInterval);
    }

    /**
     * Queue an event, draining when either threshold is reached.
     *
     * Nothing used to drain the queue before shutdown. Under PHP-FPM that is
     * harmless — the request ends in milliseconds — but a persistent worker
     * (Octane, RoadRunner, FrankenPHP, Swoole) runs for thousands of requests,
     * so the queue grew without bound and no analytics were ever delivered
     * until the process died (#2260).
     *
     * Two triggers, because one is not enough: `flushBatchSize` bounds memory
     * for a busy worker, and `flushInterval` gets a quiet one to ship at all —
     * a worker doing a handful of evaluations a minute would otherwise sit
     * below the batch threshold for hours.
     */
    public function push(Event $event): void
    {
        if ($this->queue === []) {
            $this->oldestQueuedAt = time();
        }

        $this->queue[] = $event;

        if (
            count($this->queue) >= $this->batchSize
            || (time() - (int) $this->oldestQueuedAt) >= $this->flushInterval
        ) {
            $this->flush();
        }
    }

    public function flush(): void
    {
        // Delivery goes through the caller's PSR-18 client, which may evaluate
        // a flag and queue another event. Clearing the queue below stops that
        // becoming infinite for any batch size above one; this guard covers
        // batch size one as well.
        if ($this->isFlushing || count($this->queue) === 0) {
            return;
        }

        $this->isFlushing = true;
        $batches = array_chunk($this->queue, $this->batchSize);
        $this->queue = [];
        $this->oldestQueuedAt = null;

        $dropped = 0;
        foreach ($batches as $batch) {
            $events = array_map(fn(Event $e) => $e->data, $batch);
            if (!$this->httpClient->post('/v1/sdk/events', ['events' => $events])) {
                $dropped += count($events);
            }
        }

        // One line per flush, not per batch: a request with a few thousand
        // evaluations chunks into dozens of batches, and an outage would
        // otherwise emit a log line for each of them on every request.
        $this->isFlushing = false;

        if ($dropped > 0) {
            // Never let the host's logger fail an evaluation — flush() is now
            // reachable from the evaluation path via push().
            try {
                $this->logger->warning(sprintf(
                    'dropped %d analytics event(s): %s',
                    $dropped,
                    $this->httpClient->lastError() ?? 'delivery failed',
                ));
            } catch (\Throwable) {
                // Nowhere left to report to.
            }
        }
    }

    public function queueSize(): int
    {
        return count($this->queue);
    }
}
