<?php

declare(strict_types=1);

namespace Featureflip\Tests\Events;

use Featureflip\Events\Event;
use PHPUnit\Framework\TestCase;

final class EventTest extends TestCase
{
    public function testEvaluationEventUsesPascalCaseType(): void
    {
        $event = Event::evaluation('flag-1', ['user_id' => '123'], 'on');

        $this->assertSame('Evaluation', $event->type);
        $this->assertSame('Evaluation', $event->data['type']);
    }

    public function testCustomEventUsesPascalCaseType(): void
    {
        $event = Event::custom('purchase', ['user_id' => '123'], ['amount' => 10]);

        $this->assertSame('Custom', $event->type);
        $this->assertSame('Custom', $event->data['type']);
    }

    public function testIdentifyEventUsesPascalCaseType(): void
    {
        $event = Event::identify(['user_id' => '123']);

        $this->assertSame('Identify', $event->type);
        $this->assertSame('Identify', $event->data['type']);
    }

    public function testIdentifyEventIncludesFlagKey(): void
    {
        $event = Event::identify(['user_id' => '123']);

        $this->assertSame('$identify', $event->data['flagKey']);
    }

    public function testIdentifyEventDoesNotIncludeContext(): void
    {
        $event = Event::identify(['user_id' => '123', 'email' => 'test@example.com']);

        $this->assertArrayNotHasKey('context', $event->data);
    }
}
