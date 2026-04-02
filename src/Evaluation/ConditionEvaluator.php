<?php

declare(strict_types=1);

namespace Featureflip\Evaluation;

use Featureflip\Model\Condition;

final class ConditionEvaluator
{
    /**
     * @param array<string, mixed> $context
     */
    public function evaluate(Condition $condition, array $context): bool
    {
        if (!array_key_exists($condition->attribute, $context)) {
            return $condition->negate;
        }

        $attributeValue = (string) $context[$condition->attribute];
        $result = $this->evaluateOperator($condition->operator, $attributeValue, $condition->values);

        return $condition->negate ? !$result : $result;
    }

    /**
     * @param string[] $targets
     */
    private function evaluateOperator(string $operator, string $value, array $targets): bool
    {
        // Normalize PascalCase operators from API (e.g. "NotEquals") to snake_case ("not_equals")
        $operator = strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $operator) ?? $operator);

        return match ($operator) {
            'equals', 'in' => $this->anyMatch($targets, fn(string $t) => mb_strtolower($value) === mb_strtolower($t)),
            'not_equals', 'not_in' => !$this->anyMatch($targets, fn(string $t) => mb_strtolower($value) === mb_strtolower($t)),
            'contains' => $this->anyMatch($targets, fn(string $t) => str_contains(mb_strtolower($value), mb_strtolower($t))),
            'not_contains' => !$this->anyMatch($targets, fn(string $t) => str_contains(mb_strtolower($value), mb_strtolower($t))),
            'starts_with' => $this->anyMatch($targets, fn(string $t) => str_starts_with(mb_strtolower($value), mb_strtolower($t))),
            'ends_with' => $this->anyMatch($targets, fn(string $t) => str_ends_with(mb_strtolower($value), mb_strtolower($t))),
            'matches_regex' => $this->anyMatch($targets, fn(string $t) => (bool) preg_match('~' . str_replace('~', '\\~', $t) . '~i', $value)),
            'greater_than' => count($targets) > 0 && (float) $value > (float) $targets[0],
            'greater_than_or_equal' => count($targets) > 0 && (float) $value >= (float) $targets[0],
            'less_than' => count($targets) > 0 && (float) $value < (float) $targets[0],
            'less_than_or_equal' => count($targets) > 0 && (float) $value <= (float) $targets[0],
            'before' => count($targets) > 0 && $value < $targets[0],
            'after' => count($targets) > 0 && $value > $targets[0],
            default => false,
        };
    }

    /**
     * @param string[] $targets
     * @param callable(string): bool $predicate
     */
    private function anyMatch(array $targets, callable $predicate): bool
    {
        foreach ($targets as $target) {
            if ($predicate($target)) {
                return true;
            }
        }
        return false;
    }
}
