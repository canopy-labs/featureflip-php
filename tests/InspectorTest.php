<?php

declare(strict_types=1);

namespace Featureflip\Tests;

use Featureflip\EvaluationEvent;
use Featureflip\Evaluation\Evaluator;
use Featureflip\FeatureflipClient;
use Featureflip\Model\{Condition, ConditionGroup, Flag, Prerequisite, Rule, ServeConfig, Variation, WeightedVariation};
use Featureflip\SharedFeatureflipCore;
use Featureflip\Store\FlagStore;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Psr16Cache;

/**
 * Mirrors packages/js-sdk/tests/inspector.test.ts — the reference matrix for
 * the frozen cross-SDK `onEvaluation` inspector contract.
 */
final class InspectorTest extends TestCase
{
    private const ISO_8601 = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}Z$/';

    protected function tearDown(): void
    {
        FeatureflipClient::resetForTesting();
    }

    // --- Fixtures ---------------------------------------------------------

    /**
     * @return Flag[]
     */
    private function flags(): array
    {
        $on = new Variation('on', true);
        $off = new Variation('off', false);

        return [
            ...$this->prerequisiteChainFlags($on, $off),
            // Enabled, no rules -> FALLTHROUGH serving "on".
            new Flag(
                key: 'flag-on',
                version: 1,
                type: 'boolean',
                enabled: true,
                variations: [$on, $off],
                rules: [],
                fallthrough: new ServeConfig('Fixed', 'on', null, null, null),
                offVariation: 'off',
            ),
            // Disabled -> FLAG_DISABLED serving the off variation.
            new Flag(
                key: 'flag-off',
                version: 1,
                type: 'boolean',
                enabled: false,
                variations: [$on, $off],
                rules: [],
                fallthrough: new ServeConfig('Fixed', 'on', null, null, null),
                offVariation: 'off',
            ),
            // Targeting rule on userId -> RULE_MATCH carrying the rule id.
            new Flag(
                key: 'flag-rule',
                version: 1,
                type: 'boolean',
                enabled: true,
                variations: [$on, $off],
                rules: [
                    new Rule(
                        id: 'rule-1',
                        priority: 1,
                        conditionGroups: [
                            new ConditionGroup('And', [
                                new Condition('userId', 'Equals', ['alice'], false),
                            ]),
                        ],
                        serve: new ServeConfig('Fixed', 'on', null, null, null),
                        segmentKey: null,
                    ),
                ],
                fallthrough: new ServeConfig('Fixed', 'off', null, null, null),
                offVariation: 'off',
            ),
            // Prerequisite on the disabled flag -> PREREQUISITE_FAILED.
            new Flag(
                key: 'flag-prereq',
                version: 1,
                type: 'boolean',
                enabled: true,
                variations: [$on, $off],
                rules: [],
                fallthrough: new ServeConfig('Fixed', 'on', null, null, null),
                offVariation: 'off',
                prerequisites: [new Prerequisite('flag-off', 'on')],
            ),
            // Malformed on purpose: the fallthrough serves a variation key the
            // flag does not define (e.g. a since-deleted variation) -> ERROR,
            // mirroring the engine + C#/Java (#1989).
            new Flag(
                key: 'flag-missing-variation',
                version: 1,
                type: 'boolean',
                enabled: true,
                variations: [$on, $off],
                rules: [],
                fallthrough: new ServeConfig('Fixed', 'ghost', null, null, null),
                offVariation: 'off',
            ),
            // Serves the STRING "15". Every typed accessor except
            // stringVariation() rejects it and returns its own default, so this
            // is the fixture that proves the event carries the caller's value
            // rather than the served one.
            new Flag(
                key: 'flag-string-15',
                version: 1,
                type: 'string',
                enabled: true,
                variations: [new Variation('fifteen', '15'), new Variation('empty', '')],
                rules: [],
                fallthrough: new ServeConfig('Fixed', 'fifteen', null, null, null),
                offVariation: 'empty',
            ),
            // Rollout fallthrough — bucketing casts the bucketBy attribute to a
            // string, so a non-stringable context value makes the evaluator
            // throw. Used to prove such an error fails safe to the default with
            // reason ERROR instead of reaching the caller.
            new Flag(
                key: 'flag-rollout',
                version: 1,
                type: 'boolean',
                enabled: true,
                variations: [$on, $off],
                rules: [],
                fallthrough: new ServeConfig('Rollout', 'on', null, 'salt', [
                    new WeightedVariation('on', 50),
                    new WeightedVariation('off', 50),
                ]),
                offVariation: 'off',
            ),
        ];
    }

    /**
     * A prerequisite chain longer than Evaluator::MAX_PREREQUISITE_DEPTH.
     * `flag-error` is the head: the depth cap trips deep in the chain and the
     * resulting ERROR bubbles back up, so the head evaluates with the
     * evaluator's own native `ERROR` reason on the normal (non-throwing) return
     * path. No exception is involved — this is the served-off `ERROR`, distinct
     * from the caught-exception fail-safe `ERROR` (which returns the default).
     *
     * @return Flag[]
     */
    private function prerequisiteChainFlags(Variation $on, Variation $off): array
    {
        $length = Evaluator::MAX_PREREQUISITE_DEPTH + 3;
        $flags = [];

        for ($i = 0; $i <= $length; $i++) {
            $key = $i === 0 ? 'flag-error' : "chain-{$i}";
            $next = $i === $length ? null : 'chain-' . ($i + 1);

            $flags[] = new Flag(
                key: $key,
                version: 1,
                type: 'boolean',
                enabled: true,
                variations: [$on, $off],
                rules: [],
                fallthrough: new ServeConfig('Fixed', 'on', null, null, null),
                offVariation: 'off',
                prerequisites: $next === null ? [] : [new Prerequisite($next, 'on')],
            );
        }

        return $flags;
    }

    /**
     * Build a client over a real (network-free) flag store with the given
     * inspector list. Mirrors the reflection wiring used by
     * FeatureflipClientTest::testEvaluateFlagPreservesLegitimateNullValue.
     *
     * @param array<mixed> $inspectors
     */
    private function makeClient(array $inspectors): FeatureflipClient
    {
        $store = new FlagStore(new Psr16Cache(new ArrayAdapter()), 'inspector-test', 30);
        $store->putAll($this->flags(), []);

        $coreRef = new \ReflectionClass(SharedFeatureflipCore::class);
        $coreCtor = $coreRef->getConstructor();
        $core = $coreRef->newInstanceWithoutConstructor();
        $coreCtor->invoke($core, $store, null, null, null, $inspectors);

        $clientRef = new \ReflectionClass(FeatureflipClient::class);
        $clientCtor = $clientRef->getConstructor();
        $client = $clientRef->newInstanceWithoutConstructor();
        $clientCtor->invoke($client, $core);

        return $client;
    }

    /**
     * @param EvaluationEvent[] $events
     * @return callable(EvaluationEvent): void
     */
    private function recorder(array &$events): callable
    {
        return static function (EvaluationEvent $event) use (&$events): void {
            $events[] = $event;
        };
    }

    // --- Exit paths -------------------------------------------------------

    public function testFiresOncePerCallOnTheFallthroughPath(): void
    {
        $events = [];
        $client = $this->makeClient([$this->recorder($events)]);

        $context = ['user_id' => 'bob', 'plan' => 'pro'];
        $this->assertTrue($client->boolVariation('flag-on', $context, false));

        $this->assertCount(1, $events);
        $event = $events[0];
        $this->assertSame('flag-on', $event->flagKey);
        $this->assertTrue($event->value);
        $this->assertSame('on', $event->variationKey);
        $this->assertSame('FALLTHROUGH', $event->reason);
        $this->assertNull($event->ruleId);
        $this->assertNull($event->prerequisiteKey);
        $this->assertMatchesRegularExpression(self::ISO_8601, $event->timestamp);
        $this->assertSame($context, $event->context);
    }

    public function testReportsRuleIdOnRuleMatch(): void
    {
        $events = [];
        $client = $this->makeClient([$this->recorder($events)]);

        $this->assertTrue($client->boolVariation('flag-rule', ['userId' => 'alice'], false));

        $this->assertCount(1, $events);
        $this->assertSame('RULE_MATCH', $events[0]->reason);
        $this->assertSame('rule-1', $events[0]->ruleId);
        $this->assertSame('on', $events[0]->variationKey);
        $this->assertTrue($events[0]->value);
    }

    public function testReportsFlagDisabledWithTheOffValue(): void
    {
        $events = [];
        $client = $this->makeClient([$this->recorder($events)]);

        $this->assertFalse($client->boolVariation('flag-off', ['user_id' => 'bob'], true));

        $this->assertCount(1, $events);
        $this->assertSame('FLAG_DISABLED', $events[0]->reason);
        $this->assertFalse($events[0]->value);
        $this->assertSame('off', $events[0]->variationKey);
    }

    public function testReportsFlagNotFoundWithDefaultAndNoVariationKey(): void
    {
        $events = [];
        $client = $this->makeClient([$this->recorder($events)]);

        $this->assertTrue($client->boolVariation('missing', ['user_id' => 'bob'], true));

        $this->assertCount(1, $events);
        $this->assertSame('missing', $events[0]->flagKey);
        $this->assertSame('FLAG_NOT_FOUND', $events[0]->reason);
        $this->assertTrue($events[0]->value);
        $this->assertNull($events[0]->variationKey);
        $this->assertNull($events[0]->ruleId);
        $this->assertNull($events[0]->prerequisiteKey);
    }

    public function testReportsPrerequisiteFailedWithPrerequisiteKey(): void
    {
        $events = [];
        $client = $this->makeClient([$this->recorder($events)]);

        $this->assertFalse($client->boolVariation('flag-prereq', ['user_id' => 'bob'], true));

        $this->assertCount(1, $events);
        $this->assertSame('PREREQUISITE_FAILED', $events[0]->reason);
        $this->assertSame('flag-off', $events[0]->prerequisiteKey);
        $this->assertFalse($events[0]->value);
        $this->assertSame('off', $events[0]->variationKey);
    }

    public function testReportsTheEvaluatorsNativeErrorReason(): void
    {
        $events = [];
        $client = $this->makeClient([$this->recorder($events)]);

        // Prerequisite chain deeper than MAX_PREREQUISITE_DEPTH: the depth cap
        // serves the off variation with reason ERROR, which bubbles to the head.
        $this->assertFalse($client->boolVariation('flag-error', ['user_id' => 'bob'], true));

        $this->assertCount(1, $events);
        $this->assertSame('flag-error', $events[0]->flagKey);
        $this->assertSame('ERROR', $events[0]->reason);
        // Native ERROR is served through serveOff(), so the caller gets the off
        // variation (not the default) and the event reports it verbatim.
        $this->assertFalse($events[0]->value);
        $this->assertSame('off', $events[0]->variationKey);
        $this->assertNull($events[0]->ruleId);
        $this->assertNull($events[0]->prerequisiteKey);
        $this->assertMatchesRegularExpression(self::ISO_8601, $events[0]->timestamp);
    }

    public function testReportsErrorWhenServedVariationKeyIsNotDefined(): void
    {
        $events = [];
        $client = $this->makeClient([$this->recorder($events)]);

        // The returned detail (what the caller sees) degrades to ERROR — not the
        // misleading FALLTHROUGH the evaluator resolved for the since-deleted key.
        $detail = $client->variationDetail('flag-missing-variation', ['user_id' => 'bob'], false);
        $this->assertSame('ERROR', $detail->reason);
        $this->assertSame('ghost', $detail->variationKey); // kept for diagnostics

        // The inspector sees the same ERROR reason rather than a healthy exposure.
        $this->assertCount(1, $events);
        $this->assertSame('ERROR', $events[0]->reason);
    }

    public function testEvaluatorExceptionsFailSafeToTheDefaultWithErrorReason(): void
    {
        // A flag SDK must never fail open into the host application: an
        // unexpected evaluator error degrades to the caller's default with
        // reason ERROR, mirroring every other server SDK. The inspector
        // observes it as an ERROR event, giving back the observability the
        // raised exception used to provide.
        $events = [];
        $client = $this->makeClient([$this->recorder($events)]);

        // stdClass has no __toString, so Rollout bucketing's (string) cast throws.
        $detail = $client->variationDetail('flag-rollout', ['userId' => new \stdClass()], true);

        $this->assertTrue($detail->value, 'caller receives its own default');
        $this->assertSame('ERROR', $detail->reason);
        $this->assertNull($detail->variationKey);
        $this->assertNull($detail->ruleId);
        $this->assertNull($detail->prerequisiteKey);

        // Exactly one event, reported as ERROR with the caller's value and no
        // variation diagnostics.
        $this->assertCount(1, $events);
        $this->assertSame('flag-rollout', $events[0]->flagKey);
        $this->assertSame('ERROR', $events[0]->reason);
        $this->assertTrue($events[0]->value);
        $this->assertNull($events[0]->variationKey);
        $this->assertNull($events[0]->ruleId);
        $this->assertNull($events[0]->prerequisiteKey);
    }

    public function testTypedAccessorReturnsItsDefaultWhenEvaluationThrows(): void
    {
        // The exception must not surface through a typed accessor either — it
        // returns the default rather than propagating the \Error.
        $client = $this->makeClient([]);

        $this->assertTrue(
            $client->boolVariation('flag-rollout', ['userId' => new \stdClass()], true),
        );
    }

    public function testReasonsUseNativeScreamingSnakeCase(): void
    {
        $events = [];
        $client = $this->makeClient([$this->recorder($events)]);

        $client->boolVariation('flag-on', ['user_id' => 'bob'], false);
        $client->boolVariation('flag-rule', ['userId' => 'alice'], false);
        $client->boolVariation('flag-off', ['user_id' => 'bob'], true);
        $client->boolVariation('missing', ['user_id' => 'bob'], true);
        $client->boolVariation('flag-prereq', ['user_id' => 'bob'], true);
        $client->boolVariation('flag-error', ['user_id' => 'bob'], true);

        $this->assertSame(
            ['FALLTHROUGH', 'RULE_MATCH', 'FLAG_DISABLED', 'FLAG_NOT_FOUND', 'PREREQUISITE_FAILED', 'ERROR'],
            array_map(static fn (EvaluationEvent $e): string => $e->reason, $events),
        );
    }

    public function testFiresOncePerVariationCallAcrossEveryAccessor(): void
    {
        $events = [];
        $client = $this->makeClient([$this->recorder($events)]);

        $client->boolVariation('flag-on', ['user_id' => 'bob'], false);
        $client->stringVariation('flag-on', ['user_id' => 'bob'], 'x');
        $client->numberVariation('flag-on', ['user_id' => 'bob'], 1);
        $client->jsonVariation('flag-on', ['user_id' => 'bob'], []);
        $client->variationDetail('flag-on', ['user_id' => 'bob'], false);

        $this->assertCount(5, $events);
    }

    /**
     * Per-accessor exactly-once: each of the five public accessors emits one
     * event per call — never zero (a missed notify), never two (an accessor
     * delegating to another that also notifies).
     *
     * @return iterable<string, array{callable(FeatureflipClient): mixed}>
     */
    public static function accessorProvider(): iterable
    {
        yield 'boolVariation' => [
            static fn (FeatureflipClient $c): mixed => $c->boolVariation('flag-on', ['user_id' => 'bob'], false),
        ];
        yield 'stringVariation' => [
            static fn (FeatureflipClient $c): mixed => $c->stringVariation('flag-string-15', ['user_id' => 'bob'], 'x'),
        ];
        yield 'numberVariation' => [
            static fn (FeatureflipClient $c): mixed => $c->numberVariation('flag-string-15', ['user_id' => 'bob'], 0),
        ];
        yield 'jsonVariation' => [
            static fn (FeatureflipClient $c): mixed => $c->jsonVariation('flag-on', ['user_id' => 'bob'], []),
        ];
        yield 'variationDetail' => [
            static fn (FeatureflipClient $c): mixed => $c->variationDetail('flag-on', ['user_id' => 'bob'], false),
        ];
    }

    /**
     * @param callable(FeatureflipClient): mixed $call
     */
    #[DataProvider('accessorProvider')]
    public function testEachAccessorEmitsExactlyOneEventPerCall(callable $call): void
    {
        $events = [];
        $client = $this->makeClient([$this->recorder($events)]);

        $call($client);
        $this->assertCount(1, $events, 'first call must emit exactly one event');

        $call($client);
        $this->assertCount(2, $events, 'second call must emit exactly one further event');

        $call($client);
        $this->assertCount(3, $events, 'third call must emit exactly one further event');
    }

    // --- The event value is what the CALLER received ----------------------

    public function testNumberVariationReportsTheCoercedDefaultNotTheServedString(): void
    {
        // Regression for the shipped-contract bug: the flag serves the STRING
        // "15", numberVariation()'s is_int/is_float guard rejects it and returns
        // the caller's default 0 — so the event must report 0, not "15".
        $events = [];
        $client = $this->makeClient([$this->recorder($events)]);

        $returned = $client->numberVariation('flag-string-15', ['user_id' => 'bob'], 0);

        $this->assertSame(0, $returned);
        $this->assertCount(1, $events);
        $this->assertSame(0, $events[0]->value, 'event value must be what the caller received');
        $this->assertNotSame('15', $events[0]->value);
        // The rest of the detail still describes the real evaluation.
        $this->assertSame('FALLTHROUGH', $events[0]->reason);
        $this->assertSame('fifteen', $events[0]->variationKey);
    }

    public function testBoolVariationReportsTheCoercedDefaultNotTheServedString(): void
    {
        $events = [];
        $client = $this->makeClient([$this->recorder($events)]);

        $returned = $client->boolVariation('flag-string-15', ['user_id' => 'bob'], true);

        $this->assertTrue($returned);
        $this->assertCount(1, $events);
        $this->assertTrue($events[0]->value, 'event value must be what the caller received');
    }

    public function testJsonVariationReportsTheCoercedDefaultNotTheServedString(): void
    {
        $events = [];
        $client = $this->makeClient([$this->recorder($events)]);

        $returned = $client->jsonVariation('flag-string-15', ['user_id' => 'bob'], ['fallback' => true]);

        $this->assertSame(['fallback' => true], $returned);
        $this->assertCount(1, $events);
        $this->assertSame(['fallback' => true], $events[0]->value);
    }

    public function testStringVariationReportsTheServedValueWhenTheGuardPasses(): void
    {
        // The guard passing must not change anything — the caller gets "15".
        $events = [];
        $client = $this->makeClient([$this->recorder($events)]);

        $returned = $client->stringVariation('flag-string-15', ['user_id' => 'bob'], 'fallback');

        $this->assertSame('15', $returned);
        $this->assertCount(1, $events);
        $this->assertSame('15', $events[0]->value);
    }

    public function testVariationDetailReportsTheDetailsOwnValue(): void
    {
        // variationDetail() applies no type guard, so the caller receives the
        // detail verbatim and the event must mirror it.
        $events = [];
        $client = $this->makeClient([$this->recorder($events)]);

        $detail = $client->variationDetail('flag-string-15', ['user_id' => 'bob'], 0);

        $this->assertSame('15', $detail->value);
        $this->assertCount(1, $events);
        $this->assertSame('15', $events[0]->value);
        $this->assertSame($detail->value, $events[0]->value);
    }

    // --- Registration semantics -------------------------------------------

    public function testInvokesEveryRegisteredInspector(): void
    {
        $first = [];
        $second = [];
        $client = $this->makeClient([$this->recorder($first), $this->recorder($second)]);

        $client->boolVariation('flag-on', ['user_id' => 'bob'], false);

        $this->assertCount(1, $first);
        $this->assertCount(1, $second);
    }

    public function testIsolatesAThrowingInspector(): void
    {
        $after = [];
        $errorLog = tempnam(sys_get_temp_dir(), 'ff-inspector-');
        $this->assertIsString($errorLog);
        $previous = ini_set('error_log', $errorLog);

        try {
            $client = $this->makeClient([
                static function (EvaluationEvent $event): void {
                    throw new \RuntimeException('inspector boom');
                },
                $this->recorder($after),
            ]);

            // (a) a throwing inspector does not change the returned value
            $this->assertTrue($client->boolVariation('flag-on', ['user_id' => 'bob'], false));
        } finally {
            ini_set('error_log', $previous === false ? '' : $previous);
        }

        // (b) siblings registered after the thrower still fire
        $this->assertCount(1, $after);

        // (c) the failure is logged as a warning
        $logged = (string) file_get_contents($errorLog);
        unlink($errorLog);
        $this->assertStringContainsString('[featureflip] evaluation inspector threw:', $logged);
        $this->assertStringContainsString('inspector boom', $logged);
    }

    public function testIsolatesAnInspectorThrowingAnError(): void
    {
        // \Error (not \Exception) must be contained too — hence catch \Throwable.
        $after = [];
        $errorLog = tempnam(sys_get_temp_dir(), 'ff-inspector-');
        $this->assertIsString($errorLog);
        $previous = ini_set('error_log', $errorLog);

        try {
            $client = $this->makeClient([
                static function (EvaluationEvent $event): void {
                    throw new \Error('hard failure');
                },
                $this->recorder($after),
            ]);

            $this->assertTrue($client->boolVariation('flag-on', ['user_id' => 'bob'], false));
        } finally {
            ini_set('error_log', $previous === false ? '' : $previous);
        }

        $this->assertCount(1, $after);
        $logged = (string) file_get_contents($errorLog);
        unlink($errorLog);
        $this->assertStringContainsString('hard failure', $logged);
    }

    public function testIgnoresNonCallableEntriesWithoutThrowing(): void
    {
        $events = [];
        $client = $this->makeClient([
            null,
            'not-a-callable',
            123,
            ['still', 'not', 'callable'],
            new \stdClass(),
            $this->recorder($events),
        ]);

        $this->assertTrue($client->boolVariation('flag-on', ['user_id' => 'bob'], false));
        $this->assertCount(1, $events);
    }

    public function testNoInspectorsConfiguredIsANoOp(): void
    {
        $client = $this->makeClient([]);

        $this->assertTrue($client->boolVariation('flag-on', ['user_id' => 'bob'], false));
        $this->assertFalse($client->boolVariation('flag-off', ['user_id' => 'bob'], true));
        $this->assertTrue($client->boolVariation('missing', ['user_id' => 'bob'], true));
    }

    public function testConfigInspectorsReachTheCore(): void
    {
        $config = new \Featureflip\Config(inspectors: [
            static function (EvaluationEvent $event): void {},
            'not-callable',
        ]);

        $this->assertCount(2, $config->inspectors, 'Config keeps the raw list verbatim');

        // The core is where filtering happens.
        $coreRef = new \ReflectionClass(SharedFeatureflipCore::class);
        $coreCtor = $coreRef->getConstructor();
        $core = $coreRef->newInstanceWithoutConstructor();
        $coreCtor->invoke($core, null, null, null, null, $config->inspectors);

        $prop = $coreRef->getProperty('inspectors');
        $this->assertCount(1, $prop->getValue($core));
    }

    // --- Test-mode (forTesting) stub clients ------------------------------

    public function testTestModeClientNotifiesInspectors(): void
    {
        // A user who registers an inspector and unit-tests through forTesting()
        // must still see events — the stub path is not a silent dead end.
        $events = [];
        $client = FeatureflipClient::forTesting(
            ['dark-mode' => true, 'tier' => 'pro'],
            [$this->recorder($events)],
        );

        $this->assertTrue($client->boolVariation('dark-mode', ['user_id' => 'bob'], false));

        $this->assertCount(1, $events);
        $this->assertSame('dark-mode', $events[0]->flagKey);
        $this->assertTrue($events[0]->value);
        $this->assertSame('FALLTHROUGH', $events[0]->reason);
        $this->assertSame(['user_id' => 'bob'], $events[0]->context);
        $this->assertMatchesRegularExpression(self::ISO_8601, $events[0]->timestamp);
    }

    public function testTestModeClientReportsFlagNotFoundWithTheDefault(): void
    {
        $events = [];
        $client = FeatureflipClient::forTesting([], [$this->recorder($events)]);

        $this->assertTrue($client->boolVariation('missing', ['user_id' => 'bob'], true));

        $this->assertCount(1, $events);
        $this->assertSame('FLAG_NOT_FOUND', $events[0]->reason);
        $this->assertTrue($events[0]->value);
        $this->assertNull($events[0]->variationKey);
    }

    public function testTestModeClientAppliesTheAccessorTypeGuardToTheEventValue(): void
    {
        // Same coercion contract as a live client: the stub serves the string
        // "15" but numberVariation() hands back 0.
        $events = [];
        $client = FeatureflipClient::forTesting(['count' => '15'], [$this->recorder($events)]);

        $this->assertSame(0, $client->numberVariation('count', ['user_id' => 'bob'], 0));

        $this->assertCount(1, $events);
        $this->assertSame(0, $events[0]->value);
    }

    public function testTestModeClientWithoutInspectorsStillWorks(): void
    {
        // The inspectors argument is optional — existing call sites are unaffected.
        $client = FeatureflipClient::forTesting(['dark-mode' => true]);

        $this->assertTrue($client->boolVariation('dark-mode', ['user_id' => 'bob'], false));
    }

    // --- Closed clients emit nothing ---------------------------------------

    public function testClosedClientDoesNotNotifyInspectors(): void
    {
        $events = [];
        $client = $this->makeClient([$this->recorder($events)]);

        $this->assertTrue($client->boolVariation('flag-on', ['user_id' => 'bob'], false));
        $this->assertCount(1, $events);

        $client->close();

        // A closed handle is inert: every accessor returns the caller's default
        // and none of them notify. The previous behaviour — evaluate normally,
        // suppress only the notification — was justified here as "matching the
        // Python and Ruby SDKs", which was never true: both guard every
        // accessor, and Python likewise reports ERROR from variation_detail
        // (#2267).
        $this->assertFalse($client->boolVariation('flag-on', ['user_id' => 'bob'], false));
        $this->assertTrue($client->boolVariation('flag-off', ['user_id' => 'bob'], true));
        $this->assertSame('x', $client->stringVariation('flag-string-15', ['user_id' => 'bob'], 'x'));
        $this->assertSame(0, $client->numberVariation('flag-string-15', ['user_id' => 'bob'], 0));
        $this->assertSame([], $client->jsonVariation('flag-on', ['user_id' => 'bob'], []));
        $this->assertSame('ERROR', $client->variationDetail('flag-on', ['user_id' => 'bob'], false)->reason);

        $this->assertCount(1, $events, 'no accessor may notify once the client is closed');
    }

    public function testClosedTestModeClientDoesNotNotifyInspectors(): void
    {
        $events = [];
        $client = FeatureflipClient::forTesting(['dark-mode' => true], [$this->recorder($events)]);

        $client->close();

        $this->assertFalse(
            $client->boolVariation('dark-mode', ['user_id' => 'bob'], false),
            'A closed test-stub handle is inert too — the stub is not a loophole',
        );
        $this->assertCount(0, $events);
    }

    public function testClosingOneHandleDoesNotSilenceAnother(): void
    {
        // close() is per-handle state, so a sibling handle on the same core
        // keeps notifying.
        $events = [];
        $core = SharedFeatureflipCore::createForTesting(['dark-mode' => true], [$this->recorder($events)]);

        $clientRef = new \ReflectionClass(FeatureflipClient::class);
        $clientCtor = $clientRef->getConstructor();

        $first = $clientRef->newInstanceWithoutConstructor();
        $clientCtor->invoke($first, $core);
        $second = $clientRef->newInstanceWithoutConstructor();
        $clientCtor->invoke($second, $core);

        $first->close();

        $first->boolVariation('dark-mode', ['user_id' => 'bob'], false);
        $this->assertCount(0, $events, 'the closed handle stays silent');

        $second->boolVariation('dark-mode', ['user_id' => 'bob'], false);
        $this->assertCount(1, $events, 'the open sibling handle still notifies');
    }

    // --- Context copy ------------------------------------------------------

    public function testEventContextIsACopyOfTheCallerContext(): void
    {
        $captured = null;
        $client = $this->makeClient([
            static function (EvaluationEvent $event) use (&$captured): void {
                $captured = $event;
                // A buggy inspector mutating its view of the context must not
                // reach the caller's array.
                $mutated = $event->context;
                $mutated['plan'] = 'mutated-by-inspector';
            },
        ]);

        $context = ['user_id' => 'bob', 'plan' => 'pro'];
        $client->boolVariation('flag-on', $context, false);

        $this->assertInstanceOf(EvaluationEvent::class, $captured);
        $this->assertSame(['user_id' => 'bob', 'plan' => 'pro'], $context, 'caller context is untouched');
        $this->assertSame(['user_id' => 'bob', 'plan' => 'pro'], $captured->context);

        // Mutating the caller's array after the call does not retro-change the event.
        $context['plan'] = 'enterprise';
        $this->assertSame('pro', $captured->context['plan']);
    }
}
