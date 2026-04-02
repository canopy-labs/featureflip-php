<?php

declare(strict_types=1);

namespace Featureflip\Evaluation;

use Featureflip\EvaluationDetail;
use Featureflip\Model\{Condition, ConditionGroup, Flag, Rule, Segment, ServeConfig};

final class Evaluator
{
    private ConditionEvaluator $conditionEvaluator;

    public function __construct()
    {
        $this->conditionEvaluator = new ConditionEvaluator();
    }

    /**
     * @param array<string, mixed> $context
     * @param array<string, Segment> $segments
     */
    public function evaluate(?Flag $flag, array $context, array $segments): EvaluationDetail
    {
        if ($flag === null) {
            return new EvaluationDetail(null, 'FLAG_NOT_FOUND');
        }

        if (!$flag->enabled) {
            $value = $flag->offVariation !== null
                ? $flag->getVariation($flag->offVariation)?->value
                : null;
            return new EvaluationDetail($value, 'FLAG_DISABLED');
        }

        // Sort rules by priority ascending
        $rules = $flag->rules;
        usort($rules, fn(Rule $a, Rule $b) => $a->priority <=> $b->priority);

        foreach ($rules as $rule) {
            if ($this->evaluateRule($rule, $context, $segments)) {
                $value = $this->resolveServe($flag, $rule->serve, $context);
                return new EvaluationDetail($value, 'RULE_MATCH', $rule->id, $this->resolveVariationKey($flag, $rule->serve, $context));
            }
        }

        // Fallthrough
        if ($flag->fallthrough !== null) {
            $value = $this->resolveServe($flag, $flag->fallthrough, $context);
            return new EvaluationDetail($value, 'FALLTHROUGH', null, $this->resolveVariationKey($flag, $flag->fallthrough, $context));
        }

        return new EvaluationDetail(null, 'FALLTHROUGH');
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
    private function resolveServe(Flag $flag, ServeConfig $serve, array $context): mixed
    {
        $variationKey = $this->resolveVariationKey($flag, $serve, $context);
        if ($variationKey === null) {
            return null;
        }
        return $flag->getVariation($variationKey)?->value;
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
