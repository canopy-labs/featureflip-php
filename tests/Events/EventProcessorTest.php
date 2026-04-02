<?php

declare(strict_types=1);

namespace Featureflip\Tests\Events;

use Featureflip\Events\{Event, EventProcessor};
use Featureflip\Http\HttpClient;
use PHPUnit\Framework\TestCase;

final class EventProcessorTest extends TestCase
{
    public function testQueuesEvents(): void
    {
        $httpClient = $this->createStub(HttpClient::class);
        $processor = new EventProcessor($httpClient, 100);

        $processor->push(Event::evaluation('flag-1', ['user_id' => '123'], 'on'));
        $processor->push(Event::custom('purchase', ['user_id' => '123'], ['amount' => 10]));
        $processor->push(Event::identify(['user_id' => '123', 'plan' => 'pro']));

        $this->assertSame(3, $processor->queueSize());
    }

    public function testFlushSendsEvents(): void
    {
        $httpClient = $this->createMock(HttpClient::class);
        $httpClient->expects($this->once())
            ->method('post')
            ->with('/v1/sdk/events', $this->callback(function (array $body): bool {
                return isset($body['events']) && count($body['events']) === 2;
            }));

        $processor = new EventProcessor($httpClient, 100);
        $processor->push(Event::evaluation('flag-1', ['user_id' => '123'], 'on'));
        $processor->push(Event::custom('click', ['user_id' => '123']));

        $processor->flush();
        $this->assertSame(0, $processor->queueSize());
    }

    public function testFlushDoesNothingWhenEmpty(): void
    {
        $httpClient = $this->createMock(HttpClient::class);
        $httpClient->expects($this->never())->method('post');

        $processor = new EventProcessor($httpClient, 100);
        $processor->flush();
    }

    public function testFlushBatchesLargeQueues(): void
    {
        $httpClient = $this->createMock(HttpClient::class);
        $httpClient->expects($this->exactly(2))->method('post');

        $processor = new EventProcessor($httpClient, 3);
        for ($i = 0; $i < 5; $i++) {
            $processor->push(Event::evaluation("flag-$i", ['user_id' => '123'], 'on'));
        }

        $processor->flush();
    }
}
