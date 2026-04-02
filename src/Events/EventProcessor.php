<?php

declare(strict_types=1);

namespace Featureflip\Events;

use Featureflip\Http\HttpClient;

final class EventProcessor
{
    /** @var Event[] */
    private array $queue = [];

    public function __construct(
        private readonly HttpClient $httpClient,
        private readonly int $batchSize,
    ) {}

    public function push(Event $event): void
    {
        $this->queue[] = $event;
    }

    public function flush(): void
    {
        if (count($this->queue) === 0) {
            return;
        }

        $batches = array_chunk($this->queue, $this->batchSize);
        $this->queue = [];

        foreach ($batches as $batch) {
            $events = array_map(fn(Event $e) => $e->data, $batch);
            $this->httpClient->post('/v1/sdk/events', ['events' => $events]);
        }
    }

    public function queueSize(): int
    {
        return count($this->queue);
    }
}
