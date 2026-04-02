<?php

declare(strict_types=1);

namespace Featureflip\Tests\Evaluation;

use Featureflip\Evaluation\Evaluator;
use Featureflip\EvaluationDetail;
use Featureflip\Model\{Condition, ConditionGroup, Flag, Rule, Segment, ServeConfig, Variation, WeightedVariation};
use PHPUnit\Framework\TestCase;

final class EvaluatorTest extends TestCase
{
    private Evaluator $evaluator;

    protected function setUp(): void
    {
        $this->evaluator = new Evaluator();
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
}
