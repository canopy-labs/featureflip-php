<?php

declare(strict_types=1);

namespace Featureflip\Tests\Evaluation;

use Featureflip\Evaluation\Bucketing;
use Featureflip\Evaluation\Evaluator;
use Featureflip\Evaluation\UnbucketableWarnings;
use Featureflip\EvaluationDetail;
use Featureflip\Model\{Condition, ConditionGroup, Flag, Prerequisite, Rule, Segment, ServeConfig, Variation, WeightedVariation};
use PHPUnit\Framework\TestCase;

final class EvaluatorTest extends TestCase
{
    private Evaluator $evaluator;

    protected function setUp(): void
    {
        $this->evaluator = new Evaluator();
        UnbucketableWarnings::reset();
    }

    private function boolFlag(bool $enabled = true, ?string $offVariation = 'false'): Flag
    {
        return new Flag(
            key: 'test-flag',
            version: 1,
            type: 'boolean',
            enabled: $enabled,
            variations: [
                new Variation('true', true),
                new Variation('false', false),
            ],
            rules: [],
            fallthrough: new ServeConfig('Fixed', 'true', null, null, null),
            offVariation: $offVariation,
        );
    }

    public function testFlagNotFound(): void
    {
        $detail = $this->evaluator->evaluate(null, ['user_id' => '123'], []);
        $this->assertNull($detail->value);
        $this->assertSame('FLAG_NOT_FOUND', $detail->reason);
    }

    public function testFlagDisabled(): void
    {
        $flag = $this->boolFlag(enabled: false);
        $detail = $this->evaluator->evaluate($flag, ['user_id' => '123'], []);
        $this->assertFalse($detail->value);
        $this->assertSame('FLAG_DISABLED', $detail->reason);
    }

    public function testFallthroughFixed(): void
    {
        $flag = $this->boolFlag();
        $detail = $this->evaluator->evaluate($flag, ['user_id' => '123'], []);
        $this->assertTrue($detail->value);
        $this->assertSame('FALLTHROUGH', $detail->reason);
    }

    public function testRuleMatchFixed(): void
    {
        $flag = new Flag(
            key: 'test-flag',
            version: 1,
            type: 'boolean',
            enabled: true,
            variations: [new Variation('true', true), new Variation('false', false)],
            rules: [
                new Rule(
                    id: 'rule-1',
                    priority: 0,
                    conditionGroups: [
                        new ConditionGroup('and', [new Condition('country', 'equals', ['US'], false)]),
                    ],
                    serve: new ServeConfig('Fixed', 'true', null, null, null),
                    segmentKey: null,
                ),
            ],
            fallthrough: new ServeConfig('Fixed', 'false', null, null, null),
            offVariation: 'false',
        );

        $detail = $this->evaluator->evaluate($flag, ['country' => 'US'], []);
        $this->assertTrue($detail->value);
        $this->assertSame('RULE_MATCH', $detail->reason);
        $this->assertSame('rule-1', $detail->ruleId);
    }

    public function testRuleNoMatch(): void
    {
        $flag = new Flag(
            key: 'test-flag',
            version: 1,
            type: 'boolean',
            enabled: true,
            variations: [new Variation('true', true), new Variation('false', false)],
            rules: [
                new Rule(
                    id: 'rule-1',
                    priority: 0,
                    conditionGroups: [
                        new ConditionGroup('and', [new Condition('country', 'equals', ['US'], false)]),
                    ],
                    serve: new ServeConfig('Fixed', 'true', null, null, null),
                    segmentKey: null,
                ),
            ],
            fallthrough: new ServeConfig('Fixed', 'false', null, null, null),
            offVariation: 'false',
        );

        $detail = $this->evaluator->evaluate($flag, ['country' => 'UK'], []);
        $this->assertFalse($detail->value);
        $this->assertSame('FALLTHROUGH', $detail->reason);
    }

    public function testRulePriorityOrder(): void
    {
        $flag = new Flag(
            key: 'test-flag',
            version: 1,
            type: 'string',
            enabled: true,
            variations: [new Variation('a', 'alpha'), new Variation('b', 'beta')],
            rules: [
                new Rule('rule-2', 1, [new ConditionGroup('and', [new Condition('plan', 'equals', ['pro'], false)])], new ServeConfig('Fixed', 'b', null, null, null), null),
                new Rule('rule-1', 0, [new ConditionGroup('and', [new Condition('plan', 'equals', ['pro'], false)])], new ServeConfig('Fixed', 'a', null, null, null), null),
            ],
            fallthrough: new ServeConfig('Fixed', 'b', null, null, null),
            offVariation: null,
        );

        $detail = $this->evaluator->evaluate($flag, ['plan' => 'pro'], []);
        $this->assertSame('alpha', $detail->value);
        $this->assertSame('rule-1', $detail->ruleId);
    }

    public function testRolloutServe(): void
    {
        $flag = new Flag(
            key: 'rollout-flag',
            version: 1,
            type: 'boolean',
            enabled: true,
            variations: [new Variation('true', true), new Variation('false', false)],
            rules: [],
            fallthrough: new ServeConfig('Rollout', null, 'user_id', 'rollout-flag', [
                new WeightedVariation('true', 50),
                new WeightedVariation('false', 50),
            ]),
            offVariation: 'false',
        );

        $detail = $this->evaluator->evaluate($flag, ['user_id' => 'user-123'], []);
        $this->assertIsBool($detail->value);
        $this->assertSame('FALLTHROUGH', $detail->reason);
    }

    private function thinRollout(string $bucketBy = 'userId'): ServeConfig
    {
        return new ServeConfig('Rollout', 'off', $bucketBy, 'test-salt', [
            new WeightedVariation('on', 1),
            new WeightedVariation('off', 99),
        ]);
    }

    /** @param Rule[] $rules */
    private function onOffFlag(string $key, ServeConfig $fallthrough, array $rules = [], bool $enabled = true): Flag
    {
        return new Flag(
            key: $key,
            version: 1,
            type: 'boolean',
            enabled: $enabled,
            variations: [new Variation('on', true), new Variation('off', false), new Variation('fb', 'fb')],
            rules: $rules,
            fallthrough: $fallthrough,
            offVariation: 'off',
        );
    }

    public function testKeylessRolloutSkipsToFallthroughVariation(): void
    {
        // Thin control weight (1): the old behaviour served the first variation ("on")
        // to every keyless caller. It must now serve the fallthrough's Variation.
        $flag = $this->onOffFlag('unbucketable-fallthrough', $this->thinRollout());

        for ($i = 0; $i < 20; $i++) {
            $detail = $this->evaluator->evaluate($flag, [], []);
            $this->assertSame('off', $detail->variationKey);
            $this->assertFalse($detail->value);
            $this->assertSame('FALLTHROUGH', $detail->reason);
        }
    }

    public function testCustomBucketByMissingWithUserIdPresentFallsThrough(): void
    {
        $flag = $this->onOffFlag('unbucketable-custom', $this->thinRollout('anonymous_id'));

        $detail = $this->evaluator->evaluate($flag, ['userId' => 'user-1'], []);
        $this->assertSame('off', $detail->variationKey);
        $this->assertSame('FALLTHROUGH', $detail->reason);
    }

    /** @return array<string, array{mixed}> */
    public static function nonScalarBucketValues(): array
    {
        return [
            'list' => [['a', 'b']],
            'empty array' => [[]],
            'assoc array' => [['id' => 'acme']],
            'stdClass' => [(object) ['id' => 'acme']],
            'empty stdClass' => [new \stdClass()],
        ];
    }

    /** #4572: a non-scalar can't be bucketed, so the rule is skipped for the next one. */
    #[\PHPUnit\Framework\Attributes\DataProvider('nonScalarBucketValues')]
    public function testNonScalarBucketValueSkipsRule(mixed $value): void
    {
        $r1 = new Rule('r1', 1, [], $this->thinRollout('groups'), null);
        $r2 = new Rule('r2', 2, [], new ServeConfig('Fixed', 'fb', null, null, []), null);
        $flag = $this->onOffFlag('unbucketable-non-scalar', new ServeConfig('Fixed', 'off', null, null, []), [$r1, $r2]);

        $detail = $this->evaluator->evaluate($flag, ['user_id' => 'u1', 'groups' => $value], []);
        $this->assertSame('fb', $detail->variationKey);
        $this->assertSame('r2', $detail->ruleId);
    }

    public function testKeylessRolloutRuleIsSkippedForNextRule(): void
    {
        $r1 = new Rule('r1', 1, [], $this->thinRollout(), null);
        $r2 = new Rule('r2', 2, [], new ServeConfig('Fixed', 'fb', null, null, []), null);
        $flag = $this->onOffFlag('unbucketable-next-rule', new ServeConfig('Fixed', 'off', null, null, []), [$r1, $r2]);

        $detail = $this->evaluator->evaluate($flag, [], []);
        $this->assertSame('fb', $detail->variationKey);
        $this->assertSame('RULE_MATCH', $detail->reason);
        $this->assertSame('r2', $detail->ruleId);
    }

    public function testEmptyStringBucketValueIsUnbucketableButZeroWhitespaceAndFalseBucket(): void
    {
        $flag = $this->onOffFlag('unbucketable-values', $this->thinRollout('k'));
        $this->assertSame('off', $this->evaluator->evaluate($flag, ['k' => ''], [])->variationKey);
        $this->assertSame('off', $this->evaluator->evaluate($flag, ['k' => null], [])->variationKey);

        // These bucket: a weight-1 "on" slice vs 99 "off"; assert they reach the hash path
        // by comparing to the Bucketing result directly.
        foreach ([['0', '0'], [0, '0'], [' ', ' '], [true, '1'], [false, 'false']] as [$raw, $str]) {
            $expected = Bucketing::bucket('test-salt', $str) < 1 ? 'on' : 'off';
            $this->assertSame(
                $expected,
                $this->evaluator->evaluate($flag, ['k' => $raw], [])->variationKey,
                'value ' . var_export($raw, true),
            );
        }
    }

    public function testRolloutWithoutVariationsKeylessDegradesNotSkips(): void
    {
        $r1 = new Rule('r1', 1, [], new ServeConfig('Rollout', 'on', 'userId', null, []), null);
        $flag = $this->onOffFlag('unbucketable-degrade', new ServeConfig('Fixed', 'off', null, null, []), [$r1]);
        $logger = new CollectingLogger();
        $ev = new Evaluator($logger);

        $detail = $ev->evaluate($flag, [], []);
        $this->assertSame('on', $detail->variationKey);
        $this->assertSame('RULE_MATCH', $detail->reason);
        $this->assertSame([], $logger->messages);
    }

    public function testKeylessRolloutWarnsOncePerFlagRuleAndBucketByWithoutLeakingContext(): void
    {
        $r1 = new Rule('r1', 1, [], $this->thinRollout('anonymous_id'), null);
        $flag = $this->onOffFlag('unbucketable-warn-once', new ServeConfig('Fixed', 'off', null, null, []), [$r1]);
        $logger = new CollectingLogger();
        $ev = new Evaluator($logger);

        $ctx = ['email' => 'secret@example.com'];
        $ev->evaluate($flag, $ctx, []);
        $ev->evaluate($flag, $ctx, []);

        $this->assertCount(1, $logger->messages);
        $msg = $logger->messages[0];
        $this->assertStringContainsString('unbucketable-warn-once', $msg);
        $this->assertStringContainsString('rule r1', $msg);
        $this->assertStringContainsString('anonymous_id', $msg);
        $this->assertStringNotContainsString('secret@example.com', $msg);
        $this->assertSame(
            '[featureflip] Skipped the percentage rollout in flag "unbucketable-warn-once" (rule r1): '
            . 'the context has no value for "anonymous_id". '
            . 'Pass a stable id in that attribute to include this context in the rollout.',
            $msg,
        );
    }

    public function testFallthroughSkipWarnsOnceAsFallthrough(): void
    {
        $flag = $this->onOffFlag('unbucketable-warn-fallthrough', $this->thinRollout());
        $logger = new CollectingLogger();
        $ev = new Evaluator($logger);

        $ev->evaluate($flag, [], []);
        $ev->evaluate($flag, [], []);

        $this->assertCount(1, $logger->messages);
        $this->assertStringContainsString('(fallthrough)', $logger->messages[0]);
    }

    public function testDisabledFlagKeylessDoesNotWarn(): void
    {
        $flag = $this->onOffFlag('unbucketable-warn-disabled', $this->thinRollout(), [], false);
        $logger = new CollectingLogger();
        $ev = new Evaluator($logger);

        $detail = $ev->evaluate($flag, [], []);
        $this->assertSame('FLAG_DISABLED', $detail->reason);
        $this->assertSame([], $logger->messages);
    }

    public function testKeylessRolloutInPrerequisiteFailsDependent(): void
    {
        $prereq = $this->onOffFlag('unbucketable-prereq', $this->thinRollout());
        $dependent = new Flag(
            key: 'unbucketable-dependent',
            version: 1,
            type: 'boolean',
            enabled: true,
            variations: [new Variation('on', true), new Variation('off', false)],
            rules: [],
            fallthrough: new ServeConfig('Fixed', 'on', null, null, []),
            offVariation: 'off',
            prerequisites: [new Prerequisite('unbucketable-prereq', 'on')],
        );

        $detail = $this->evaluator->evaluate(
            $dependent,
            [],
            [],
            ['unbucketable-prereq' => $prereq, 'unbucketable-dependent' => $dependent],
        );
        $this->assertSame('PREREQUISITE_FAILED', $detail->reason);
    }

    public function testRolloutServeWithNoVariationsServesDefault(): void
    {
        // Env-level PercentageRollout emits a Rollout serve with its default variation set but
        // no weighted variations (no per-variation weight storage at the env level, #1469).
        // Degrade to the default variation instead of returning null. Mirrors the engine + C#/Java.
        $flag = new Flag(
            key: 'rollout-flag',
            version: 1,
            type: 'boolean',
            enabled: true,
            variations: [new Variation('on', true), new Variation('off', false)],
            rules: [],
            fallthrough: new ServeConfig('Rollout', 'off', 'userId', null, []),
            offVariation: 'off',
        );

        $detail = $this->evaluator->evaluate($flag, ['userId' => 'user-1'], []);
        $this->assertFalse($detail->value);
        $this->assertSame('FALLTHROUGH', $detail->reason);
    }

    public function testSegmentMatch(): void
    {
        $segment = new Segment('beta-users', 1, [
            new Condition('plan', 'equals', ['pro'], false),
        ], 'and');

        $flag = new Flag(
            key: 'test-flag',
            version: 1,
            type: 'boolean',
            enabled: true,
            variations: [new Variation('true', true), new Variation('false', false)],
            rules: [
                new Rule('rule-1', 0, [], new ServeConfig('Fixed', 'true', null, null, null), 'beta-users'),
            ],
            fallthrough: new ServeConfig('Fixed', 'false', null, null, null),
            offVariation: 'false',
        );

        $detail = $this->evaluator->evaluate($flag, ['plan' => 'pro'], ['beta-users' => $segment]);
        $this->assertTrue($detail->value);
        $this->assertSame('RULE_MATCH', $detail->reason);
    }

    public function testConditionGroupOrOperator(): void
    {
        $flag = new Flag(
            key: 'test-flag',
            version: 1,
            type: 'boolean',
            enabled: true,
            variations: [new Variation('true', true), new Variation('false', false)],
            rules: [
                new Rule('rule-1', 0, [
                    new ConditionGroup('or', [
                        new Condition('country', 'equals', ['US'], false),
                        new Condition('country', 'equals', ['UK'], false),
                    ]),
                ], new ServeConfig('Fixed', 'true', null, null, null), null),
            ],
            fallthrough: new ServeConfig('Fixed', 'false', null, null, null),
            offVariation: 'false',
        );

        $detail = $this->evaluator->evaluate($flag, ['country' => 'UK'], []);
        $this->assertTrue($detail->value);
        $this->assertSame('RULE_MATCH', $detail->reason);
    }

    public function testConditionGroupPascalCaseOrOperator(): void
    {
        $flag = new Flag(
            key: 'test-flag',
            version: 1,
            type: 'boolean',
            enabled: true,
            variations: [new Variation('true', true), new Variation('false', false)],
            rules: [
                new Rule('rule-1', 0, [
                    new ConditionGroup('Or', [
                        new Condition('country', 'Equals', ['US'], false),
                        new Condition('country', 'Equals', ['UK'], false),
                    ]),
                ], new ServeConfig('Fixed', 'true', null, null, null), null),
            ],
            fallthrough: new ServeConfig('Fixed', 'false', null, null, null),
            offVariation: 'false',
        );

        $detail = $this->evaluator->evaluate($flag, ['country' => 'UK'], []);
        $this->assertTrue($detail->value);
        $this->assertSame('RULE_MATCH', $detail->reason);
    }

    public function testPascalCaseOperatorsInRuleMatch(): void
    {
        $flag = new Flag(
            key: 'test-flag',
            version: 1,
            type: 'boolean',
            enabled: true,
            variations: [new Variation('true', true), new Variation('false', false)],
            rules: [
                new Rule(
                    id: 'rule-1',
                    priority: 0,
                    conditionGroups: [
                        new ConditionGroup('And', [new Condition('country', 'Equals', ['US'], false)]),
                    ],
                    serve: new ServeConfig('Fixed', 'true', null, null, null),
                    segmentKey: null,
                ),
            ],
            fallthrough: new ServeConfig('Fixed', 'false', null, null, null),
            offVariation: 'false',
        );

        $detail = $this->evaluator->evaluate($flag, ['country' => 'US'], []);
        $this->assertTrue($detail->value);
        $this->assertSame('RULE_MATCH', $detail->reason);
    }

    public function testMultipleConditionGroupsAllMustMatch(): void
    {
        $flag = new Flag(
            key: 'test-flag',
            version: 1,
            type: 'boolean',
            enabled: true,
            variations: [new Variation('true', true), new Variation('false', false)],
            rules: [
                new Rule('rule-1', 0, [
                    new ConditionGroup('and', [new Condition('country', 'equals', ['US'], false)]),
                    new ConditionGroup('and', [new Condition('plan', 'equals', ['pro'], false)]),
                ], new ServeConfig('Fixed', 'true', null, null, null), null),
            ],
            fallthrough: new ServeConfig('Fixed', 'false', null, null, null),
            offVariation: 'false',
        );

        // Both groups match
        $detail = $this->evaluator->evaluate($flag, ['country' => 'US', 'plan' => 'pro'], []);
        $this->assertTrue($detail->value);
        $this->assertSame('RULE_MATCH', $detail->reason);

        // Only first group matches — should fallthrough
        $detail = $this->evaluator->evaluate($flag, ['country' => 'US', 'plan' => 'free'], []);
        $this->assertFalse($detail->value);
        $this->assertSame('FALLTHROUGH', $detail->reason);

        // Only second group matches — should fallthrough
        $detail = $this->evaluator->evaluate($flag, ['country' => 'UK', 'plan' => 'pro'], []);
        $this->assertFalse($detail->value);
        $this->assertSame('FALLTHROUGH', $detail->reason);
    }

    public function testEmptyConditionGroupsMatchesAll(): void
    {
        $flag = new Flag(
            key: 'test-flag',
            version: 1,
            type: 'boolean',
            enabled: true,
            variations: [new Variation('true', true), new Variation('false', false)],
            rules: [
                new Rule('rule-1', 0, [], new ServeConfig('Fixed', 'true', null, null, null), null),
            ],
            fallthrough: new ServeConfig('Fixed', 'false', null, null, null),
            offVariation: 'false',
        );

        $detail = $this->evaluator->evaluate($flag, ['user_id' => '123'], []);
        $this->assertTrue($detail->value);
        $this->assertSame('RULE_MATCH', $detail->reason);
    }

    /**
     * @param Prerequisite[] $prerequisites
     */
    private function makeFlag(
        string $key = 'main',
        bool $enabled = true,
        string $fallthroughVariation = 'on',
        array $prerequisites = [],
    ): Flag {
        return new Flag(
            key: $key,
            version: 1,
            type: 'boolean',
            enabled: $enabled,
            variations: [new Variation('on', true), new Variation('off', false)],
            rules: [],
            fallthrough: new ServeConfig('Fixed', $fallthroughVariation, null, null, null),
            offVariation: 'off',
            prerequisites: $prerequisites,
        );
    }

    public function testNoPrerequisitesFallsThrough(): void
    {
        $flag = $this->makeFlag();
        $detail = $this->evaluator->evaluate($flag, ['user_id' => 'u'], [], []);
        $this->assertSame('FALLTHROUGH', $detail->reason);
        $this->assertTrue($detail->value);
        $this->assertNull($detail->prerequisiteKey);
    }

    public function testSatisfiedPrerequisiteFallsThrough(): void
    {
        $prereq = $this->makeFlag(key: 'prereq', fallthroughVariation: 'on');
        $main = $this->makeFlag(
            key: 'main',
            prerequisites: [new Prerequisite('prereq', 'on')],
        );

        $detail = $this->evaluator->evaluate(
            $main,
            ['user_id' => 'u'],
            [],
            ['prereq' => $prereq],
        );

        $this->assertSame('FALLTHROUGH', $detail->reason);
        $this->assertTrue($detail->value);
        $this->assertNull($detail->prerequisiteKey);
    }

    public function testUnsatisfiedPrerequisiteServesOff(): void
    {
        $prereq = $this->makeFlag(key: 'prereq', fallthroughVariation: 'off');
        $main = $this->makeFlag(
            key: 'main',
            prerequisites: [new Prerequisite('prereq', 'on')],
        );

        $detail = $this->evaluator->evaluate(
            $main,
            ['user_id' => 'u'],
            [],
            ['prereq' => $prereq],
        );

        $this->assertSame('PREREQUISITE_FAILED', $detail->reason);
        $this->assertFalse($detail->value);
        $this->assertSame('prereq', $detail->prerequisiteKey);
        $this->assertSame('off', $detail->variationKey);
    }

    public function testDisabledPrerequisiteServesOff(): void
    {
        $prereq = $this->makeFlag(key: 'prereq', enabled: false);
        $main = $this->makeFlag(
            key: 'main',
            prerequisites: [new Prerequisite('prereq', 'on')],
        );

        $detail = $this->evaluator->evaluate(
            $main,
            ['user_id' => 'u'],
            [],
            ['prereq' => $prereq],
        );

        $this->assertSame('PREREQUISITE_FAILED', $detail->reason);
        $this->assertSame('prereq', $detail->prerequisiteKey);
    }

    public function testMultiPrereqReportsFirstFailingKey(): void
    {
        $a = $this->makeFlag(key: 'prereq-a', fallthroughVariation: 'off');
        $b = $this->makeFlag(key: 'prereq-b', fallthroughVariation: 'off');
        $main = $this->makeFlag(
            key: 'main',
            prerequisites: [
                new Prerequisite('prereq-a', 'on'),
                new Prerequisite('prereq-b', 'on'),
            ],
        );

        $detail = $this->evaluator->evaluate(
            $main,
            ['user_id' => 'u'],
            [],
            ['prereq-a' => $a, 'prereq-b' => $b],
        );

        $this->assertSame('PREREQUISITE_FAILED', $detail->reason);
        $this->assertSame('prereq-a', $detail->prerequisiteKey);
    }

    public function testChainedPrerequisitesResolveRecursively(): void
    {
        $grandchild = $this->makeFlag(key: 'grandchild', fallthroughVariation: 'on');
        $child = $this->makeFlag(
            key: 'child',
            fallthroughVariation: 'on',
            prerequisites: [new Prerequisite('grandchild', 'on')],
        );
        $main = $this->makeFlag(
            key: 'main',
            prerequisites: [new Prerequisite('child', 'on')],
        );

        $detail = $this->evaluator->evaluate(
            $main,
            ['user_id' => 'u'],
            [],
            ['grandchild' => $grandchild, 'child' => $child],
        );
        $this->assertSame('FALLTHROUGH', $detail->reason);
        $this->assertTrue($detail->value);

        // Break the chain at the grandchild level — failure bubbles to main.
        $grandchildFailing = $this->makeFlag(
            key: 'grandchild',
            fallthroughVariation: 'off',
        );
        $detail2 = $this->evaluator->evaluate(
            $main,
            ['user_id' => 'u'],
            [],
            ['grandchild' => $grandchildFailing, 'child' => $child],
        );
        $this->assertSame('PREREQUISITE_FAILED', $detail2->reason);
    }

    public function testMissingPrerequisiteFlagFailsSafely(): void
    {
        $main = $this->makeFlag(
            key: 'main',
            prerequisites: [new Prerequisite('missing-flag', 'on')],
        );

        $detail = $this->evaluator->evaluate($main, ['user_id' => 'u'], [], []);

        $this->assertSame('PREREQUISITE_FAILED', $detail->reason);
        $this->assertSame('missing-flag', $detail->prerequisiteKey);
        $this->assertFalse($detail->value);
    }

    public function testDepthExceededReturnsError(): void
    {
        $flags = [];
        for ($i = 0; $i < 12; $i++) {
            $prereqs = $i > 0 ? [new Prerequisite("flag-" . ($i - 1), 'on')] : [];
            $flags["flag-$i"] = $this->makeFlag(
                key: "flag-$i",
                fallthroughVariation: 'on',
                prerequisites: $prereqs,
            );
        }

        $detail = $this->evaluator->evaluate(
            $flags['flag-11'],
            ['user_id' => 'u'],
            [],
            $flags,
        );

        $this->assertSame('ERROR', $detail->reason);
    }

    public function testEvaluateWithSharedMemoReusesResult(): void
    {
        $prereq = $this->makeFlag(key: 'prereq', fallthroughVariation: 'on');
        $main = $this->makeFlag(
            key: 'main',
            prerequisites: [new Prerequisite('prereq', 'on')],
        );

        $memo = [];
        $detail = $this->evaluator->evaluateWithSharedMemo(
            $main,
            ['user_id' => 'u'],
            [],
            ['prereq' => $prereq],
            $memo,
        );

        $this->assertSame('FALLTHROUGH', $detail->reason);
        $this->assertArrayHasKey('prereq', $memo);
        $this->assertArrayHasKey('main', $memo);
    }

    public function testBackwardCompatibleEvaluateSignature(): void
    {
        // Existing callers that omit $allFlags must still work for flags
        // without prerequisites.
        $flag = $this->makeFlag();
        $detail = $this->evaluator->evaluate($flag, ['user_id' => 'u'], []);
        $this->assertSame('FALLTHROUGH', $detail->reason);
        $this->assertTrue($detail->value);
    }

    public function testRolloutEmptySaltUsesEmptyNotFlagKey(): void
    {
        // A null/empty salt must hash as '' (engine behavior), not the flag key.
        // Pre-fix PHP substitutes $flag->key for both null and '' salts, so the
        // served variation diverges from Bucketing::bucket('', uid) for some users.
        $flag = new Flag(
            key: 'rollout-flag',
            version: 1,
            type: 'Boolean',
            enabled: true,
            variations: [new Variation('on', true), new Variation('off', false)],
            rules: [],
            fallthrough: new ServeConfig('Rollout', null, 'userId', null, [
                new WeightedVariation('on', 50),
                new WeightedVariation('off', 50),
            ]),
            offVariation: 'off',
        );

        for ($i = 0; $i < 50; $i++) {
            $uid = "user-$i";
            $detail = $this->evaluator->evaluate($flag, ['user_id' => $uid], []);
            $expected = Bucketing::bucket('', $uid) < 50 ? 'on' : 'off';
            $this->assertSame($expected, $detail->variationKey, "user $uid");
        }

        // An explicit empty-string salt must behave identically to null (both hash as '').
        // Pre-fix PHP mapped BOTH null and '' to $flag->key; the `?? ''` fix only catches null,
        // so this case exercises the empty-string branch the old guard also rewrote.
        $emptySaltFlag = new Flag(
            key: 'rollout-flag',
            version: 1,
            type: 'Boolean',
            enabled: true,
            variations: [new Variation('on', true), new Variation('off', false)],
            rules: [],
            fallthrough: new ServeConfig('Rollout', null, 'userId', '', [
                new WeightedVariation('on', 50),
                new WeightedVariation('off', 50),
            ]),
            offVariation: 'off',
        );

        for ($i = 0; $i < 50; $i++) {
            $uid = "user-$i";
            $detail = $this->evaluator->evaluate($emptySaltFlag, ['user_id' => $uid], []);
            $expected = Bucketing::bucket('', $uid) < 50 ? 'on' : 'off';
            $this->assertSame($expected, $detail->variationKey, "empty-salt user $uid");
        }
    }

    public function testRuleServingUnknownVariationIsErrorWithoutRuleId(): void
    {
        $rule = new Rule('r1', 1, [], new ServeConfig('Fixed', 'nope', null, null, null), null);
        $flag = $this->onOffFlag('unknown-rule-variation', new ServeConfig('Fixed', 'off', null, null, []), [$rule]);

        $detail = $this->evaluator->evaluate($flag, ['userId' => 'u'], []);
        $this->assertSame('ERROR', $detail->reason);
        $this->assertSame('nope', $detail->variationKey);
        $this->assertNull($detail->value);
        $this->assertNull($detail->ruleId);
    }

    public function testFallthroughServingUnknownVariationIsError(): void
    {
        $flag = $this->onOffFlag('unknown-fall-variation', new ServeConfig('Fixed', 'nope', null, null, []), []);

        $detail = $this->evaluator->evaluate($flag, ['userId' => 'u'], []);
        $this->assertSame('ERROR', $detail->reason);
        $this->assertSame('nope', $detail->variationKey);
    }

    public function testFloatBucketValueHashesAsItsCanonicalSpelling(): void
    {
        // (string) would spell these "0.3", "1.0E+21", "1.0E-7" (precision 14, PHP exponent form).
        $cases = [
            [0.30000000000000004, '0.30000000000000004'],
            [1e21, '1e+21'],
            [1e-7, '1e-7'],
            [123456.78901234567, '123456.78901234567'],
            [1.5e300, '1.5e+300'],
            [0.1 + 0.7, '0.7999999999999999'],
            [1.0, '1'],
        ];
        foreach ($cases as [$float, $spelled]) {
            $flag = $this->onOffFlag(
                'float-bucket',
                new ServeConfig('Rollout', null, 'id', 'salt', [
                    new \Featureflip\Model\WeightedVariation('on', 50),
                    new \Featureflip\Model\WeightedVariation('off', 50),
                ]),
            );
            $got = $this->evaluator->evaluate($flag, ['id' => $float], [])->variationKey;
            $want = $this->evaluator->evaluate($flag, ['id' => $spelled], [])->variationKey;
            $this->assertSame($want, $got, $spelled);
        }
    }

    public function testDisabledFlagWithUnknownOffVariationIsError(): void
    {
        $detail = $this->evaluator->evaluate($this->boolFlag(enabled: false, offVariation: 'zzz'), ['user_id' => '1'], []);
        $this->assertSame('ERROR', $detail->reason);
        $this->assertSame('zzz', $detail->variationKey);
        $this->assertNull($detail->value);
    }

    public function testFailedPrerequisiteWithUnknownOffVariationIsError(): void
    {
        $parent = $this->makeFlag(key: 'p', enabled: false);
        $main = new Flag(
            key: 'main',
            version: 1,
            type: 'boolean',
            enabled: true,
            variations: [new Variation('on', true), new Variation('off', false)],
            rules: [],
            fallthrough: new ServeConfig('Fixed', 'on', null, null, null),
            offVariation: 'zzz',
            prerequisites: [new Prerequisite('p', 'on')],
        );
        $detail = $this->evaluator->evaluate($main, ['user_id' => '1'], [], ['p' => $parent, 'main' => $main]);
        $this->assertSame('ERROR', $detail->reason);
        $this->assertSame('zzz', $detail->variationKey);
        $this->assertNull($detail->value);
    }

    public function testDependentOfUnknownOffPrerequisiteIsErrorServingItsOwnOff(): void
    {
        $parent = new Flag(
            key: 'p',
            version: 1,
            type: 'boolean',
            enabled: false,
            variations: [new Variation('on', true), new Variation('off', false)],
            rules: [],
            fallthrough: new ServeConfig('Fixed', 'on', null, null, null),
            offVariation: 'zzz',
        );
        $main = $this->makeFlag(prerequisites: [new Prerequisite('p', 'on')]);
        $detail = $this->evaluator->evaluate($main, ['user_id' => '1'], [], ['p' => $parent, 'main' => $main]);
        $this->assertSame('ERROR', $detail->reason);
        $this->assertSame('off', $detail->variationKey);
        $this->assertFalse($detail->value);
    }
}
