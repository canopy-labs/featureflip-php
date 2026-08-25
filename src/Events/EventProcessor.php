<?php

declare(strict_types=1);

namespace Featureflip\Events;

use Featureflip\Http\HttpClient;
use Featureflip\Http\PostFailure;
use Featureflip\Logging\ErrorLogLogger;
use Psr\Log\LoggerInterface;

/**
 * @internal Not part of the public API.
 */
final class EventProcessor
{
    /**
     * Upper bound on buffered events.
     *
     * Only reachable once batches start coming back faster than they drain —
     * i.e. a sustained outage of the events endpoint. Past the bound the OLDEST
     * events are shed, which caps memory and keeps the freshest analytics. It
     * also means a long outage sheds the re-queued (stale) batches first rather
     * than starving new events, so the SDK degrades to the old drop-everything
     * behaviour instead of hoarding data it cannot send.
     */
    public const DEFAULT_MAX_QUEUE_SIZE = 10000;

    /** @var Event[] */
    private array $queue = [];

    private LoggerInterface $logger;

    private readonly int $flushInterval;

    private readonly int $maxQueueSize;

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

    /** Set by close(): the final flush has happened and nothing more is accepted. */
    private bool $isClosed = false;

    /**
     * Unix time before which the batch-SIZE trigger must not start another flush.
     *
     * A re-queued batch leaves the queue at or above `batchSize`, so without this
     * gate every subsequent event would start another flush — turning a failing
     * endpoint into one request per evaluation, which is worse for the server
     * than the dropping this replaced. The INTERVAL trigger is the retry vehicle
     * and is deliberately not gated; this only suppresses the size trigger
     * between its ticks.
     */
    private int $nextAutoFlushAt = 0;

    public function __construct(
        private readonly HttpClient $httpClient,
        private readonly int $batchSize,
        ?LoggerInterface $logger = null,
        int $flushInterval = 30,
        int $maxQueueSize = self::DEFAULT_MAX_QUEUE_SIZE,
    ) {
        $this->logger = $logger ?? new ErrorLogLogger();
        // Floored for the same reason the refresh backoff is: asking for zero
        // is not asking for one HTTP POST per evaluation.
        $this->flushInterval = max(1, $flushInterval);
        // A bound of zero would shed every event the moment it was queued.
        $this->maxQueueSize = max(1, $maxQueueSize);
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
        // Nothing will flush again after close(), so queueing here would only
        // leak. The client's own guards mean this is belt and braces.
        if ($this->isClosed) {
            return;
        }

        if ($this->queue === []) {
            $this->oldestQueuedAt = time();
        }

        $this->queue[] = $event;
        $this->shedOverflow();

        $now = time();

        if (
            (count($this->queue) >= $this->batchSize && $now >= $this->nextAutoFlushAt)
            || ($now - (int) $this->oldestQueuedAt) >= $this->flushInterval
        ) {
            $this->flush();
        }
    }

    public function flush(): void
    {
        // Delivery goes through the caller's PSR-18 client, which may evaluate
        // a flag and queue another event. Taking the batches as a snapshot below
        // stops that becoming infinite for any batch size above one; this guard
        // covers batch size one as well.
        if ($this->isFlushing || $this->queue === []) {
            return;
        }

        $this->isFlushing = true;
        $size = max(1, $this->batchSize);
        $snapshot = $this->queue;
        $batches = array_chunk($snapshot, $size);
        $this->queue = [];
        $this->oldestQueuedAt = null;

        // Handed back to the queue for the next flush.
        $requeued = 0;
        // Rejected permanently — no later attempt would fare any better.
        $dropped = 0;
        // Still undelivered when the last flush of this process ran.
        $discarded = 0;
        $failure = null;

        foreach ($batches as $index => $batch) {
            $events = array_map(static fn (Event $e): array => $e->data, $batch);

            if ($this->httpClient->post('/v1/sdk/events', ['events' => $events])) {
                // Delivery works again, so the size trigger is allowed to fire.
                $this->nextAutoFlushAt = 0;
                continue;
            }

            $failure = $this->httpClient->lastFailure();

            // A failure the HTTP client could not describe is treated as
            // transport: the safe assumption is that the batch never arrived.
            if ($failure === null || $failure->isRetryable()) {
                // This batch AND the ones behind it, in order — retrying belongs
                // to the NEXT flush, not this one. Carrying on down the list
                // would keep POSTing into an endpoint that has just said it
                // cannot take them, one request per batch, for the whole outage.
                // Sliced from the snapshot rather than re-merging the remaining
                // chunks: with a batch size of one that would spread ten thousand
                // arrays into a single array_merge() call.
                $held = array_slice($snapshot, $index * $size);

                if ($this->isClosed) {
                    // close() is the last attempt by definition, so there is no
                    // next flush to keep these for.
                    $discarded = count($held);
                    break;
                }

                $requeued = count($held) - $this->requeue($held);
                $this->nextAutoFlushAt = time() + $this->flushInterval;
                break;
            }

            $dropped += count($events);
        }

        $this->isFlushing = false;

        $this->report($requeued, $dropped, $discarded, $failure);
    }

    /**
     * Flush what is queued one last time, then let the rest go.
     *
     * Called from the shutdown handler, so it must not loop or block: retrying
     * until the queue empties would hang the process for as long as the endpoint
     * stayed down, and nothing will flush after this anyway.
     */
    public function close(): void
    {
        if ($this->isClosed) {
            return;
        }

        // Set BEFORE the flush so a retryable failure is reported as discarded
        // rather than kept for a flush that will never come.
        $this->isClosed = true;
        $this->flush();
        $this->queue = [];
        $this->oldestQueuedAt = null;
    }

    public function queueSize(): int
    {
        return count($this->queue);
    }

    /**
     * Put a batch that failed to send back at the FRONT of the queue, so the
     * next flush retries it ahead of newer events and rough chronological order
     * survives.
     *
     * @param Event[] $events
     *
     * @return int how many were shed to stay within the bound
     */
    private function requeue(array $events): int
    {
        $this->queue = array_merge($events, $this->queue);

        // Stamped now, not with the batch's original arrival time: the interval
        // trigger measures from the oldest queued event, so re-using the old
        // stamp would leave it permanently satisfied and fire a retry on every
        // subsequent push. Stamping now rate-limits the retry to one attempt per
        // interval, which is what makes the interval trigger a safe retry vehicle.
        $this->oldestQueuedAt = time();

        return $this->shedOverflow();
    }

    /**
     * Shed oldest-first until the queue fits the bound.
     *
     * @return int how many events were shed
     */
    private function shedOverflow(): int
    {
        $overflow = count($this->queue) - $this->maxQueueSize;
        if ($overflow <= 0) {
            return 0;
        }

        array_splice($this->queue, 0, $overflow);
        $this->warn(sprintf(
            'event queue is full; dropped %d of the oldest analytics event(s)',
            $overflow,
        ));

        return $overflow;
    }

    /**
     * One line per flush, not per batch: a request with a few thousand
     * evaluations chunks into dozens of batches, and an outage would otherwise
     * emit a log line for each of them on every request. The counts are exact;
     * the reason names the most recent failure, since one flush can meet more
     * than one.
     */
    private function report(int $requeued, int $dropped, int $discarded, ?PostFailure $failure): void
    {
        if ($requeued === 0 && $dropped === 0 && $discarded === 0) {
            return;
        }

        $parts = [];
        if ($requeued > 0) {
            $parts[] = sprintf('re-queued %d analytics event(s) for the next flush', $requeued);
        }
        if ($dropped > 0) {
            $parts[] = sprintf('dropped %d analytics event(s) as non-retryable', $dropped);
        }
        if ($discarded > 0) {
            $parts[] = sprintf('dropped %d analytics event(s) still undelivered at shutdown', $discarded);
        }

        $this->warn(implode('; ', $parts) . ': ' . ($failure?->message ?? 'delivery failed'));
    }

    private function warn(string $message): void
    {
        // Never let the host's logger fail an evaluation — flush() is reachable
        // from the evaluation path via push().
        try {
            $this->logger->warning($message);
        } catch (\Throwable) {
            // Nowhere left to report to.
        }
    }
}
