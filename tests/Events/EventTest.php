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

    // --- Payload shape: parity with the other server SDKs -----------------

    public function testIdentifyForwardsTheCallersAttributesAsMetadata(): void
    {
        $event = Event::identify([
            'user_id' => '123',
            'email' => 'test@example.com',
            'plan' => 'pro',
        ]);

        $this->assertSame(
            ['email' => 'test@example.com', 'plan' => 'pro'],
            $event->data['metadata']
        );
    }

    public function testIdentifyStripsBothIdentitySpellingsFromMetadata(): void
    {
        $event = Event::identify([
            'user_id' => 'canonical',
            'userId' => 'alias',
            'plan' => 'pro',
        ]);

        $this->assertSame('canonical', $event->data['userId']);
        $this->assertSame(['plan' => 'pro'], $event->data['metadata']);
    }

    public function testIdentifyOmitsMetadataWhenOnlyTheIdentityWasSupplied(): void
    {
        $event = Event::identify(['user_id' => '123']);

        $this->assertArrayNotHasKey('metadata', $event->data);
    }

    public function testCustomOmitsMetadataWhenNoneWasSupplied(): void
    {
        $event = Event::custom('purchase', ['user_id' => '123']);

        $this->assertArrayNotHasKey('metadata', $event->data);
    }

    /**
     * An empty PHP array encodes as a JSON array (`[]`), which the backend's
     * `Dictionary<string, JsonElement>?` binder rejects. Populated metadata has
     * string keys and so encodes as an object; the empty case must be absent
     * rather than present-and-wrongly-typed.
     */
    public function testPopulatedMetadataEncodesAsAJsonObject(): void
    {
        $event = Event::custom('purchase', ['user_id' => '123'], ['amount' => 10]);

        $this->assertSame('{"amount":10}', json_encode($event->data['metadata']));
    }

    public function testUserIdIsOmittedWhenTheContextCarriesNoIdentity(): void
    {
        $event = Event::identify(['plan' => 'pro']);

        $this->assertArrayNotHasKey('userId', $event->data);
    }

    public function testEvaluationOmitsUserIdWhenTheContextCarriesNoIdentity(): void
    {
        $event = Event::evaluation('flag-1', ['plan' => 'pro'], 'on');

        $this->assertArrayNotHasKey('userId', $event->data);
    }

    // --- Timestamps are UTC regardless of the host's timezone -------------

    /**
     * `new \DateTimeImmutable()` with no explicit zone uses the PROCESS default,
     * so an event's timestamp used to carry whatever `date.timezone` the
     * customer's PHP-FPM pool happened to run in. Every other server SDK emits
     * UTC.
     *
     * This is not a bucketing bug — `format('c')` always writes an explicit
     * offset, so the instant is unambiguous and the backend's
     * BucketMath.TruncateToHour normalises it correctly. It matters because the
     * string is forwarded verbatim to a customer's own analytics provider under
     * the BYO-analytics webhook (#1801), and because the offset leaks which
     * timezone the caller's infrastructure runs in (#2399).
     *
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('hostTimezoneProvider')]
    public function testEventTimestampsAreUtcWhateverTheHostTimezone(string $timezone): void
    {
        $original = date_default_timezone_get();
        date_default_timezone_set($timezone);

        try {
            $events = [
                'evaluation' => Event::evaluation('flag-1', ['user_id' => '123'], 'on'),
                'custom' => Event::custom('purchase', ['user_id' => '123'], ['amount' => 10]),
                'identify' => Event::identify(['user_id' => '123']),
            ];

            foreach ($events as $name => $event) {
                $this->assertMatchesRegularExpression(
                    '/(Z|\+00:00)$/',
                    $event->data['timestamp'],
                    "the {$name} event carried a non-UTC timestamp under {$timezone}"
                );
            }
        } finally {
            // Process-global: leaking it would silently retune every later test.
            date_default_timezone_set($original);
        }
    }

    /**
     * A positive and a negative offset, so a bug that merely hardcoded one sign
     * cannot pass.
     *
     * @return array<string, array{string}>
     */
    public static function hostTimezoneProvider(): array
    {
        return [
            'UTC' => ['UTC'],
            'negative offset' => ['America/New_York'],
            'positive offset' => ['Asia/Tokyo'],
        ];
    }

    /**
     * Pins the exact spelling, not just UTC-ness: milliseconds and a `Z`
     * suffix, matching SharedFeatureflipCore's inspector events and the js SDK.
     * Reverting to `format('c')` would still be UTC and would still pass the
     * test above, but would silently change the bytes on the wire.
     */
    public function testTheEmittedTimestampUsesMillisecondsAndAZSuffix(): void
    {
        $event = Event::identify(['user_id' => '123']);

        $this->assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}Z$/',
            $event->data['timestamp']
        );
    }

    public function testTheEmittedTimestampIsTheCorrectInstant(): void
    {
        $original = date_default_timezone_get();
        date_default_timezone_set('Asia/Tokyo');

        try {
            $before = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
            $event = Event::identify(['user_id' => '123']);
            $after = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

            // Shifting the wall clock instead of the zone would still satisfy the
            // format assertion above; this pins the instant itself.
            $stamped = new \DateTimeImmutable($event->data['timestamp']);
            $this->assertGreaterThanOrEqual($before->getTimestamp(), $stamped->getTimestamp());
            $this->assertLessThanOrEqual($after->getTimestamp(), $stamped->getTimestamp());
        } finally {
            date_default_timezone_set($original);
        }
    }
}
