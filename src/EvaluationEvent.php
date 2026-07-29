<?php

declare(strict_types=1);

namespace Featureflip;

/**
 * A single flag evaluation, handed synchronously to every inspector registered
 * via {@see Config::$inspectors}.
 *
 * This is the frozen cross-SDK inspector contract — field names and semantics
 * mirror every other Featureflip SDK. `reason` carries this SDK's **native**
 * SCREAMING_SNAKE_CASE casing (`FLAG_DISABLED`, `FLAG_NOT_FOUND`, `RULE_MATCH`,
 * `FALLTHROUGH`, `PREREQUISITE_FAILED`, `ERROR`); it is deliberately NOT
 * converted to the JS SDK's PascalCase, consistent with the existing
 * per-evaluator reason-string split.
 *
 * Inspectors are void observers — the returned value is ignored.
 */
final class EvaluationEvent
{
    /**
     * @param string               $flagKey         Key of the flag that was evaluated.
     * @param array<string, mixed> $context         Copy of the caller's full evaluation
     *                                              context. PHP arrays are value types, so
     *                                              a buggy inspector cannot mutate the
     *                                              caller's array.
     * @param mixed                $value           The value the caller receives (default applied).
     * @param string|null          $variationKey    Winning arm; null on flag-not-found and error.
     * @param string               $reason          Native SCREAMING_SNAKE_CASE reason.
     * @param string|null          $ruleId          Set only when `$reason` is `RULE_MATCH`.
     * @param string|null          $prerequisiteKey Set only when `$reason` is `PREREQUISITE_FAILED`.
     * @param string               $timestamp       ISO-8601 UTC instant.
     */
    public function __construct(
        public readonly string $flagKey,
        public readonly array $context,
        public readonly mixed $value,
        public readonly ?string $variationKey,
        public readonly string $reason,
        public readonly ?string $ruleId,
        public readonly ?string $prerequisiteKey,
        public readonly string $timestamp,
    ) {}
}
