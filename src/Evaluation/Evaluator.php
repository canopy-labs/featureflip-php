<?php

declare(strict_types=1);

namespace Featureflip\Evaluation;

use Featureflip\EvaluationDetail;
use Featureflip\Model\{Condition, ConditionGroup, Flag, Prerequisite, Rule, Segment, ServeConfig};

final class Evaluator
{
    public const MAX_PREREQUISITE_DEPTH = 10;

    private ConditionEvaluator $conditionEvaluator;

    public function __construct()
    {
        $this->conditionEvaluator = new ConditionEvaluator();
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
                $variationKey = $this->resolveVariationKey($flag, $rule->serve, $context);
                $value = $variationKey !== null ? $flag->getVariation($variationKey)?->value : null;
                $result = new EvaluationDetail($value, 'RULE_MATCH', $rule->id, $variationKey);
                $memo[$flag->key] = $result;
                return $result;
            }
        }

        if ($flag->fallthrough !== null) {
            $variationKey = $this->resolveVariationKey($flag, $flag->fallthrough, $context);
            $value = $variationKey !== null ? $flag->getVariation($variationKey)?->value : null;
            $result = new EvaluationDetail($value, 'FALLTHROUGH', null, $variationKey);
            $memo[$flag->key] = $result;
            return $result;
        }

        $result = new EvaluationDetail(null, 'FALLTHROUGH');
        $memo[$flag->key] = $result;
        return $result;
    }

    private function serveOff(Flag $flag, string $reason, ?string $prerequisiteKey = null): EvaluationDetail
    {
        $value = $flag->offVariation !== null
            ? $flag->getVariation($flag->offVariation)?->value
            : null;
        return new EvaluationDetail($value, $reason, null, $flag->offVariation, $prerequisiteKey);
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
     * @param array<string, mixed> $context
     */
    private function resolveVariationKey(Flag $flag, ServeConfig $serve, array $context): ?string
    {
        if ($serve->type === 'Fixed') {
            return $serve->variation;
        }

        // Rollout
        if ($serve->variations === null || count($serve->variations) === 0) {
            return null;
        }

        $bucketBy = $serve->bucketBy ?? 'userId';
        $bucketValue = $context[$bucketBy] ?? null;
        // Alias "userId" <-> "user_id" for the built-in user identifier
        if ($bucketValue === null && $bucketBy === 'userId') {
            $bucketValue = $context['user_id'] ?? null;
        } elseif ($bucketValue === null && $bucketBy === 'user_id') {
            $bucketValue = $context['userId'] ?? null;
        }
        $bucketValue = $bucketValue !== null ? (string) $bucketValue : '';
        $salt = $serve->salt !== null && $serve->salt !== '' ? $serve->salt : $flag->key;
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
