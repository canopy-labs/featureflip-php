<?php

declare(strict_types=1);

namespace Featureflip\Tests\Events;

use Featureflip\Events\{Event, EventProcessor};
use Featureflip\FeatureflipClient;
use Featureflip\Http\HttpClient;
use Featureflip\Model\{Flag, ServeConfig, Variation};
use Featureflip\SharedFeatureflipCore;
use Featureflip\Store\FlagStore;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Psr16Cache;

/**
 * How an event derives its `userId` from the caller's evaluation context.
 *
 * The governing rule is that nothing here may throw into the host application.
 * #1990 made the evaluator fail safe, but the event-tracking call sits just
 * after it in SharedFeatureflipCore::evaluateFlag() and outside that guard, so
 * a context value PHP cannot cast to string still escaped into the caller's
 * request path (#2259). track()/identify() reach the same code with no guard at
 * all.
 */
final class EventContextTest extends TestCase
{
    private EventProcessor $processor;

    protected function tearDown(): void
    {
        FeatureflipClient::resetForTesting();
    }

    // --- Event factories: never throw ------------------------------------

    public function testEvaluationEventDoesNotThrowOnNonStringableContextValue(): void
    {
        $event = Event::evaluation('flag-1', ['user_id' => new \stdClass()], 'on');

        $this->assertSame('', $event->data['userId']);
    }

    public function testCustomEventDoesNotThrowOnNonStringableContextValue(): void
    {
        $event = Event::custom('purchase', ['user_id' => new \stdClass()], ['amount' => 10]);

        $this->assertSame('', $event->data['userId']);
    }

    public function testIdentifyEventDoesNotThrowOnNonStringableContextValue(): void
    {
        $event = Event::identify(['user_id' => new \stdClass()]);

        $this->assertSame('', $event->data['userId']);
    }

    /**
     * An array context value only raises a PHP warning rather than an Error,
     * but Symfony and Laravel both promote warnings to ErrorException in their
     * default error handlers — so in a real application it throws just the
     * same.
     */
    public function testEvaluationEventDoesNotEmitAWarningForAnArrayContextValue(): void
    {
        set_error_handler(static function (int $no, string $str): bool {
            throw new \ErrorException($str, 0, $no);
        });

        try {
            $event = Event::evaluation('flag-1', ['user_id' => ['a', 'b']], 'on');
        } finally {
            restore_error_handler();
        }

        $this->assertSame('', $event->data['userId']);
    }

    // --- Event factories: legitimate values still survive -----------------

    public function testStringableContextValueIsStringified(): void
    {
        $id = new class () implements \Stringable {
            public function __toString(): string
            {
                return 'user-123';
            }
        };

        $event = Event::evaluation('flag-1', ['user_id' => $id], 'on');

        $this->assertSame('user-123', $event->data['userId']);
    }

    public function testNumericContextValueIsStringified(): void
    {
        $event = Event::evaluation('flag-1', ['user_id' => 123], 'on');

        $this->assertSame('123', $event->data['userId']);
    }

    // --- Event factories: userId alias ------------------------------------

    /**
     * `user_id` is the canonical wire field and `userId` an accepted alias —
     * Evaluator::resolveVariationKey() already honours both when bucketing, so
     * a caller who uses the alias consistently was bucketed correctly but had
     * every analytics event attributed to a blank user.
     */
    public function testCamelCaseUserIdAliasIsHonouredWhenCanonicalKeyIsAbsent(): void
    {
        $event = Event::evaluation('flag-1', ['userId' => 'user-123'], 'on');

        $this->assertSame('user-123', $event->data['userId']);
    }

    public function testCanonicalUserIdWinsOverTheAlias(): void
    {
        $event = Event::evaluation('flag-1', ['user_id' => 'canonical', 'userId' => 'alias'], 'on');

        $this->assertSame('canonical', $event->data['userId']);
    }

    public function testAliasAppliesToCustomAndIdentifyEventsToo(): void
    {
        $this->assertSame('user-123', Event::custom('purchase', ['userId' => 'user-123'])->data['userId']);
        $this->assertSame('user-123', Event::identify(['userId' => 'user-123'])->data['userId']);
    }


    public function testBackedEnumContextValueUsesItsBackingValue(): void
    {
        $event = Event::evaluation('flag-1', ['user_id' => EventContextTestPlan::Pro], 'on');

        $this->assertSame('pro', $event->data['userId']);
    }

    public function testAnUnrenderableContextValueIsLogged(): void
    {
        $log = tempnam(sys_get_temp_dir(), 'ff-log-');
        $previous = ini_set('error_log', $log);

        try {
            Event::evaluation('flag-1', ['user_id' => new \stdClass()], 'on');
        } finally {
            ini_set('error_log', (string) $previous);
        }

        $contents = (string) file_get_contents($log);
        unlink($log);

        $this->assertStringContainsString('[featureflip]', $contents);
        $this->assertStringContainsString('stdClass', $contents);
    }

    public function testAnAbsentUserIdIsNotLogged(): void
    {
        $log = tempnam(sys_get_temp_dir(), 'ff-log-');
        $previous = ini_set('error_log', $log);

        try {
            Event::evaluation('flag-1', ['plan' => 'pro'], 'on');
            Event::evaluation('flag-1', ['user_id' => null], 'on');
        } finally {
            ini_set('error_log', (string) $previous);
        }

        $contents = (string) file_get_contents($log);
        unlink($log);

        $this->assertSame('', $contents, 'An anonymous context is normal, not an error worth logging');
    }

    /**
     * Real user classes just define __toString(); PHP 8 grants them Stringable
     * implicitly. Pinned so a narrowing of the helper cannot silently drop them.
     */
    public function testImplicitlyStringableContextValueIsStringified(): void
    {
        $id = new class () {
            public function __toString(): string
            {
                return 'user-123';
            }
        };

        $event = Event::evaluation('flag-1', ['user_id' => $id], 'on');

        $this->assertSame('user-123', $event->data['userId']);
    }

    /**
     * Only a null/absent canonical key falls through to the alias — a falsy but
     * present value does not. Pins the `??` semantics against a future `?:`.
     */
    public function testAFalsyCanonicalUserIdDoesNotFallThroughToTheAlias(): void
    {
        $event = Event::evaluation('flag-1', ['user_id' => false, 'userId' => 'alias'], 'on');

        $this->assertSame('', $event->data['userId']);
    }

    // --- The regression proper: nothing escapes into the host -------------

    public function testBoolVariationDoesNotThrowWhenContextValueIsNotStringable(): void
    {
        $client = $this->makeLiveClient();

        $this->assertTrue($client->boolVariation('flag-on', ['user_id' => new \stdClass()], false));
    }

    public function testVariationDetailDoesNotThrowWhenContextValueIsNotStringable(): void
    {
        $client = $this->makeLiveClient();

        $detail = $client->variationDetail('flag-on', ['user_id' => new \stdClass()], false);

        $this->assertSame('FALLTHROUGH', $detail->reason);
    }

    public function testTrackDoesNotThrowWhenContextValueIsNotStringable(): void
    {
        $client = $this->makeLiveClient();

        $client->track('checkout-completed', ['user_id' => new \stdClass()], ['total' => 99.99]);

        $this->assertSame(1, $this->processor->queueSize());
        $this->assertSame('', $this->lastQueuedUserId());
    }

    public function testIdentifyDoesNotThrowWhenContextValueIsNotStringable(): void
    {
        $client = $this->makeLiveClient();

        $client->identify(['user_id' => new \stdClass(), 'plan' => 'pro']);

        $this->assertSame(1, $this->processor->queueSize());
        $this->assertSame('', $this->lastQueuedUserId());
    }

    // --- Fixtures ---------------------------------------------------------

    /**
     * The userId actually carried by the most recently queued event — proves the
     * degradation produced a blank attribution rather than merely not throwing.
     */
    private function lastQueuedUserId(): string
    {
        $queue = (new \ReflectionProperty(EventProcessor::class, 'queue'))->getValue($this->processor);

        return end($queue)->data['userId'];
    }

    /**
     * A client wired to a real FlagStore and a real EventProcessor, so the
     * event-construction path actually runs. FeatureflipClient::forTesting()
     * builds a core with a null event processor, which is exactly why the
     * existing suite never reached this bug.
     */
    private function makeLiveClient(): FeatureflipClient
    {
        $store = new FlagStore(new Psr16Cache(new ArrayAdapter()), 'event-context-test', 30);
        $store->putAll([
            new Flag(
                key: 'flag-on',
                version: 1,
                type: 'boolean',
                enabled: true,
                variations: [new Variation('on', true), new Variation('off', false)],
                rules: [],
                fallthrough: new ServeConfig('Fixed', 'on', null, null, null),
                offVariation: 'off',
            ),
        ], []);

        // A stubbed transport: queueSize() is what the assertions read, so the
        // events are never actually posted.
        $this->processor = new EventProcessor($this->createStub(HttpClient::class), 100);

        $coreRef = new \ReflectionClass(SharedFeatureflipCore::class);
        $core = $coreRef->newInstanceWithoutConstructor();
        $coreRef->getConstructor()->invoke($core, $store, $this->processor, null, null, []);

        $clientRef = new \ReflectionClass(FeatureflipClient::class);
        $client = $clientRef->newInstanceWithoutConstructor();
        $clientRef->getConstructor()->invoke($client, $core);

        return $client;
    }
}

enum EventContextTestPlan: string
{
    case Pro = 'pro';
}
