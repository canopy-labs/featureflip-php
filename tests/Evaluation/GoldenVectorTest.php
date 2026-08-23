<?php

declare(strict_types=1);

namespace Featureflip\Tests\Evaluation;

use Featureflip\Evaluation\Bucketing;
use Featureflip\Evaluation\Evaluator;
use Featureflip\Model\Flag;
use Featureflip\Model\Segment;
use PHPUnit\Framework\TestCase;

/**
 * Cross-SDK golden-vector parity harness (issue #1477).
 *
 * Runs all four vector groups from packages/php-sdk/tests/golden/vectors.json
 * against the PHP SDK's real Bucketing and Evaluator implementations.
 *
 * DO NOT edit vectors.json to make PHP pass — if a vector fails, fix the PHP SDK.
 */
final class GoldenVectorTest extends TestCase
{
    /** @var array<string, mixed> */
    private static array $vectors;

    /** @var array<string, string> */
    private static array $reasonMap = [
        'FLAG_DISABLED'      => 'FlagDisabled',
        'RULE_MATCH'         => 'RuleMatch',
        'FALLTHROUGH'        => 'Fallthrough',
        'PREREQUISITE_FAILED' => 'PrerequisiteFailed',
        'FLAG_NOT_FOUND'     => 'FlagNotFound',
        'ERROR'              => 'Error',
    ];

    public static function setUpBeforeClass(): void
    {
        $path = __DIR__ . '/../golden/vectors.json';
        $json = file_get_contents($path);
        self::$vectors = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    }

    public function testBucketVectors(): void
    {
        foreach (self::$vectors['bucketVectors'] as $v) {
            $this->assertSame(
                $v['expectedBucket'],
                Bucketing::bucket($v['salt'], $v['value']),
                $v['id'],
            );
        }
    }

    public function testRolloutVectors(): void
    {
        $ev = new Evaluator();
        foreach (self::$vectors['rolloutVectors'] as $v) {
            $flag = Flag::fromArray([
                'key'          => 'rollout-test',
                'version'      => 1,
                'type'         => 'String',
                'enabled'      => true,
                'variations'   => array_map(
                    fn(array $w) => ['key' => $w['key'], 'value' => $w['key']],
                    $v['variations'],
                ),
                'rules'        => [],
                'fallthrough'  => [
                    'type'       => 'Rollout',
                    'salt'       => $v['salt'],
                    'bucketBy'   => 'userId',
                    'variations' => $v['variations'],
                ],
                'offVariation' => $v['variations'][0]['key'],
            ]);
            $detail = $ev->evaluate($flag, ['userId' => $v['value']], []);
            $this->assertSame($v['expectedVariation'], $detail->variationKey, $v['id']);
        }
    }

    public function testConditionVectors(): void
    {
        $this->runConditionVectors(self::$vectors['conditionVectors']);
    }

    /**
     * Locks the rule that an operator this SDK does not recognise means "cannot
     * evaluate", NOT "did not match" — so negate must never invert it into a
     * match-everyone, which would serve the flag to 100% of traffic (#2262).
     *
     * Hand-authored vectors, not engine-generated: the generator resolves
     * operators with Enum.Parse<ConditionOperator>, which throws on an
     * unrecognised name. PHP carries the operator as a raw string, so unlike the
     * enum-typed SDKs it can genuinely receive one of these over the wire.
     */
    public function testUnknownOperatorVectors(): void
    {
        $this->runConditionVectors(self::$vectors['unknownOperatorVectors']);
    }

    /**
     * Builds the single-condition flag each vector describes and asserts whether
     * the "match" variation is served. Shared by the engine-generated condition
     * vectors and the hand-authored unknown-operator vectors, which have an
     * identical input shape.
     *
     * @param array<int, array<string, mixed>> $vectors
     */
    private function runConditionVectors(array $vectors): void
    {
        $this->assertNotEmpty($vectors);
        $ev = new Evaluator();
        foreach ($vectors as $v) {
            $flag = Flag::fromArray([
                'key'        => 'cond-test',
                'version'    => 1,
                'type'       => 'String',
                'enabled'    => true,
                'variations' => [
                    ['key' => 'match',   'value' => 'match'],
                    ['key' => 'nomatch', 'value' => 'nomatch'],
                ],
                'rules' => [
                    [
                        'id'       => 'r',
                        'priority' => 0,
                        'serve'    => ['type' => 'Fixed', 'variation' => 'match'],
                        'conditionGroups' => [
                            [
                                'operator'   => 'And',
                                'conditions' => [
                                    [
                                        'attribute' => 'attr',
                                        'operator'  => $v['operator'],
                                        'values'    => $v['values'],
                                        'negate'    => $v['negate'] ?? false,
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
                'fallthrough'  => ['type' => 'Fixed', 'variation' => 'nomatch'],
                'offVariation' => 'nomatch',
            ]);

            // Put the raw typed value into context so the #1458 numeric-coercion
            // path (is_int || is_float) triggers correctly.
            $detail = $ev->evaluate($flag, ['attr' => $v['attribute']['value']], []);
            $this->assertSame(
                $v['expectedMatch'],
                $detail->variationKey === 'match',
                $v['id'],
            );
        }
    }

    public function testFlagVectors(): void
    {
        $ev = new Evaluator();
        foreach (self::$vectors['flagVectors'] as $v) {
            // Build flag map keyed by flag key.
            $allFlags = [];
            foreach ($v['flags'] as $f) {
                $allFlags[$f['key']] = Flag::fromArray($f);
            }

            // Build segment map keyed by segment key ($segments is array<string, Segment>).
            $segments = [];
            foreach ($v['segments'] ?? [] as $s) {
                $seg = Segment::fromArray($s);
                $segments[$seg->key] = $seg;
            }

            // Flatten context: userId at the top level + attributes merged in.
            $ctx = array_merge(
                ['userId' => $v['context']['userId'] ?? null],
                $v['context']['attributes'] ?? [],
            );

            $detail = $ev->evaluate($allFlags[$v['flagKey']], $ctx, $segments, $allFlags);
            $exp    = $v['expected'];

            $this->assertSame($exp['variation'], $detail->variationKey, $v['id']);
            $this->assertSame(
                json_encode($exp['value']),
                json_encode($detail->value),
                $v['id'],
            );

            // Normalise UPPER_SNAKE reason → canonical PascalCase for cross-SDK comparison.
            $reason = ['kind' => self::$reasonMap[$detail->reason] ?? $detail->reason];
            if ($detail->ruleId !== null) {
                $reason['ruleId'] = $detail->ruleId;
            }
            if ($detail->prerequisiteKey !== null) {
                $reason['prerequisiteKey'] = $detail->prerequisiteKey;
            }
            $this->assertEquals($exp['reason'], $reason, $v['id']);
        }
    }
}
