<?php

declare(strict_types=1);

namespace Featureflip\Evaluation;

use Featureflip\EvaluationDetail;
use Featureflip\Model\{Condition, ConditionGroup, Flag, Prerequisite, Rule, Segment, ServeConfig};
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

final class Evaluator
{
    public const MAX_PREREQUISITE_DEPTH = 10;

    /**
     * Returned by resolveVariationKey() when a Rollout serve cannot bucket the
     * context. Distinct from null, which means "no variation key" (#4236).
     */
    private const UNBUCKETABLE = "\0unbucketable";

    private ConditionEvaluator $conditionEvaluator;
    private LoggerInterface $logger;

    public function __construct(?LoggerInterface $logger = null)
    {
        $this->conditionEvaluator = new ConditionEvaluator();
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * Evaluate a flag against a context.
     *
     * @param array<string, mixed> $context
     * @param array<string, Segment> $segments
     * @param array<string, Flag> $allFlags Map of all flags in the environment,
     *                                       keyed by flag key. Required for
     *                                       prerequisite resolution; pass an
     *                                       empty array when the flag has no
     *                                       prerequisites.
     */
    public function evaluate(?Flag $flag, array $context, array $segments, array $allFlags = []): EvaluationDetail
    {
        if ($flag === null) {
            return new EvaluationDetail(null, 'FLAG_NOT_FOUND');
        }

        $memo = [];
        return $this->evaluateInternal($flag, $context, $segments, $allFlags, 0, $memo);
    }

    /**
     * Evaluate a flag while sharing a memoisation map with other calls.
     *
     * Use this when evaluating multiple flags in one batch so shared
     * prerequisites are only evaluated once.
     *
     * @param array<string, mixed> $context
     * @param array<string, Segment> $segments
     * @param array<string, Flag> $allFlags
     * @param array<string, EvaluationDetail> $memo Reference parameter; updated
     *                                              with each flag's result.
     */
    public function evaluateWithSharedMemo(
        Flag $flag,
        array $context,
        array $segments,
        array $allFlags,
        array &$memo,
    ): EvaluationDetail {
        return $this->evaluateInternal($flag, $context, $segments, $allFlags, 0, $memo);
    }

    /**
     * @param array<string, mixed> $context
     * @param array<string, Segment> $segments
     * @param array<string, Flag> $allFlags
     * @param array<string, EvaluationDetail> $memo
     */
    private function evaluateInternal(
        Flag $flag,
        array $context,
        array $segments,
        array $allFlags,
        int $depth,
        array &$memo,
    ): EvaluationDetail {
        // Guard: cycle detection happens at write time; this is a safety net.
        if ($depth > self::MAX_PREREQUISITE_DEPTH) {
            $result = $this->serveOff($flag, 'ERROR');
            $memo[$flag->key] = $result;
            return $result;
        }

        if (!$flag->enabled) {
            $result = $this->serveOff($flag, 'FLAG_DISABLED');
            $memo[$flag->key] = $result;
            return $result;
        }

        // Resolve prerequisites before rules or fallthrough.
        foreach ($flag->prerequisites as $prereq) {
            $prereqResult = $memo[$prereq->prerequisiteFlagKey] ?? null;

            if ($prereqResult === null) {
                $prereqFlag = $allFlags[$prereq->prerequisiteFlagKey] ?? null;

                if ($prereqFlag === null) {
                    // Missing flag: fail safely. Memo key is the current flag's
                    // key (matches the JS reference) — the prereq itself has
                    // no result to memoise.
                    $result = $this->serveOff(
                        $flag,
                        'PREREQUISITE_FAILED',
                        $prereq->prerequisiteFlagKey,
                    );
                    $memo[$flag->key] = $result;
                    return $result;
                }

                $prereqResult = $this->evaluateInternal(
                    $prereqFlag,
                    $context,
                    $segments,
                    $allFlags,
                    $depth + 1,
                    $memo,
                );
                $memo[$prereq->prerequisiteFlagKey] = $prereqResult;
            }

            // Bubble errors from recursive evaluation.
            if ($prereqResult->reason === 'ERROR') {
                $result = $this->serveOff($flag, 'ERROR');
                $memo[$flag->key] = $result;
                return $result;
            }

            if ($prereqResult->variationKey !== $prereq->expectedVariationKey) {
                $result = $this->serveOff(
                    $flag,
                    'PREREQUISITE_FAILED',
                    $prereq->prerequisiteFlagKey,
                );
                $memo[$flag->key] = $result;
                return $result;
            }
        }

        // Sort rules by priority ascending
        $rules = $flag->rules;
        usort($rules, fn(Rule $a, Rule $b) => $a->priority <=> $b->priority);

        foreach ($rules as $rule) {
            if ($this->evaluateRule($rule, $context, $segments)) {
                $variationKey = $this->resolveVariationKey($rule->serve, $context);
                if ($variationKey === self::UNBUCKETABLE) {
                    // Can't bucket this context: the rule doesn't match; try the next one.
                    UnbucketableWarnings::warn($this->logger, $flag->key, $rule->id, $rule->serve->bucketBy ?? 'user_id');
                    continue;
                }
                $variationKey ??= '';
                $variation = $flag->getVariation($variationKey);
                if ($variation === null) {
                    // The served key names no variation: malformed config. Report ERROR (no
                    // rule id) with the served key, like the engine's ServeVariation (#4236).
                    $result = new EvaluationDetail(null, 'ERROR', null, $variationKey);
                    $memo[$flag->key] = $result;
                    return $result;
                }
                $result = new EvaluationDetail($variation->value, 'RULE_MATCH', $rule->id, $variationKey);
                $memo[$flag->key] = $result;
                return $result;
            }
        }

        if ($flag->fallthrough !== null) {
            $variationKey = $this->resolveVariationKey($flag->fallthrough, $context);
            if ($variationKey === self::UNBUCKETABLE) {
                UnbucketableWarnings::warn($this->logger, $flag->key, null, $flag->fallthrough->bucketBy ?? 'user_id');
                $variationKey = $flag->fallthrough->variation;
            }
            $variationKey ??= '';
            $variation = $flag->getVariation($variationKey);
            if ($variation === null) {
                $result = new EvaluationDetail(null, 'ERROR', null, $variationKey);
                $memo[$flag->key] = $result;
                return $result;
            }
            $result = new EvaluationDetail($variation->value, 'FALLTHROUGH', null, $variationKey);
            $memo[$flag->key] = $result;
            return $result;
        }

        $result = new EvaluationDetail(null, 'FALLTHROUGH');
        $memo[$flag->key] = $result;
        return $result;
    }

    private function serveOff(Flag $flag, string $reason, ?string $prerequisiteKey = null): EvaluationDetail
    {
        $variation = $flag->offVariation !== null ? $flag->getVariation($flag->offVariation) : null;
        if ($variation === null) {
            // An off variation the flag doesn't define is malformed config: report ERROR
            // (no value, the key kept), like the engine's ServeVariation (#4236).
            return new EvaluationDetail(null, 'ERROR', null, $flag->offVariation);
        }
        return new EvaluationDetail($variation->value, $reason, null, $flag->offVariation, $prerequisiteKey);
    }

    /**
     * @param array<string, mixed> $context
     * @param array<string, Segment> $segments
     */
    private function evaluateRule(Rule $rule, array $context, array $segments): bool
    {
        if ($rule->segmentKey !== null) {
            $segment = $segments[$rule->segmentKey] ?? null;
            if ($segment === null) {
                return false;
            }
            return $this->evaluateConditions($segment->conditions, $segment->conditionLogic, $context);
        }

        return $this->evaluateConditionGroups($rule->conditionGroups, $context);
    }

    /**
     * All groups must match (AND). Within each group, conditions use the group's operator.
     *
     * @param ConditionGroup[] $groups
     * @param array<string, mixed> $context
     */
    private function evaluateConditionGroups(array $groups, array $context): bool
    {
        if (count($groups) === 0) {
            return true;
        }

        foreach ($groups as $group) {
            if (!$this->evaluateConditions($group->conditions, $group->operator, $context)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param Condition[] $conditions
     * @param array<string, mixed> $context
     */
    private function evaluateConditions(array $conditions, string $logic, array $context): bool
    {
        if (count($conditions) === 0) {
            return true;
        }

        if (strtolower($logic) === 'or') {
            foreach ($conditions as $condition) {
                if ($this->conditionEvaluator->evaluate($condition, $context)) {
                    return true;
                }
            }
            return false;
        }

        // AND logic (default)
        foreach ($conditions as $condition) {
            if (!$this->conditionEvaluator->evaluate($condition, $context)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Returns the served variation key, null when the serve names none, or
     * {@see self::UNBUCKETABLE} when a Rollout serve can't bucket the context.
     * Only Rollout serves can be unbucketable; a Fixed serve never is.
     *
     * @param array<string, mixed> $context
     */
    private function resolveVariationKey(ServeConfig $serve, array $context): ?string
    {
        if ($serve->type === 'Fixed') {
            return $serve->variation;
        }

        // Rollout
        // A Rollout serve can arrive with no weighted variations — env-level PercentageRollout
        // has nowhere to store per-variation weights, so the mapper emits type=Rollout with no
        // variations (#1469). Degrade to the default fixed variation instead of returning null.
        // Mirrors the engine + C#/Java SDK evaluators.
        if ($serve->variations === null || count($serve->variations) === 0) {
            return $serve->variation;
        }

        $bucketBy = $serve->bucketBy ?? 'user_id';
        // "user_id" and "userId" resolve the same identity (user_id first).
        $bucketValue = ContextAttribute::resolve($context, $bucketBy);
        // A missing, null or exactly-empty value can't be bucketed, for any bucketBy.
        // Whitespace, "0", 0 and false are real values and bucket normally. A bool hashes as the
        // JSON's "true"/"false" ((string) true is "1" and (string) false is "", which would
        // read as can't-bucket); every other value keeps the plain (string) cast (#4236).
        // A float uses the canonical number spelling shared with the engine ((string) would use
        // precision 14); integers keep their digits.
        // An array, or an object that is not Stringable (a decoded JSON array or object, empty
        // or not), has no spelling every SDK shares, so it can't be bucketed (#4572).
        if (is_array($bucketValue) || (is_object($bucketValue) && !($bucketValue instanceof \Stringable))) {
            return self::UNBUCKETABLE;
        }
        if (is_bool($bucketValue)) {
            $bucketValue = $bucketValue ? 'true' : 'false';
        } elseif (is_float($bucketValue)) {
            $bucketValue = CanonicalNumber::format($bucketValue);
        } else {
            $bucketValue = (string) ($bucketValue ?? '');
        }
        if ($bucketValue === '') {
            return self::UNBUCKETABLE;
        }
        $salt = $serve->salt ?? '';
        $bucket = Bucketing::bucket($salt, $bucketValue);

        $cumulative = 0;
        foreach ($serve->variations as $wv) {
            $cumulative += $wv->weight;
            if ($bucket < $cumulative) {
                return $wv->key;
            }
        }

        // Fallback to last variation
        return $serve->variations[count($serve->variations) - 1]->key;
    }
}
