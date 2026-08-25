<?php

declare(strict_types=1);

namespace Featureflip\Model;

/**
 * Thrown when a flag or segment carries an enum value this SDK build cannot EVALUATE —
 * an unrecognised `serve.type` or `conditionLogic` (#2402).
 *
 * `serve.type` and `conditionLogic` are the two enums that are BOTH carried on the wire
 * as strings AND consulted by the evaluator, and each dispatches on a two-way branch
 * with no third arm:
 *
 *     $serve->type === 'Fixed'      ... else ROLLOUT
 *     strtolower($logic) === 'or'   ... else AND
 *
 * So an unrecognised value does not fail — it takes the ELSE arm and quietly evaluates
 * as something else. (php lands on the safer side of that split for condition logic,
 * defaulting to AND where js/go/ruby default to OR, but "quietly evaluates as something
 * the server did not ask for" is the same defect either way.)
 *
 * Deliberately a DIFFERENT type from the `InvalidArgumentException` {@see RequiredField}
 * throws, and the distinction is the whole point. That one means the payload is the
 * wrong SHAPE. This one means the payload is perfectly well-formed and simply describes
 * behaviour a newer server understands and this build does not — so the entity is
 * dropped for a stated, different reason, and {@see \Featureflip\DataSource\Poller} says
 * so in its own words rather than calling a valid payload malformed.
 *
 * Not thrown for an unrecognised condition OPERATOR: the evaluator already fails an
 * unknown operator closed (#2262), so the condition simply does not match and the flag
 * remains evaluable.
 *
 * @internal
 */
final class UnevaluableEntityException extends \RuntimeException
{
    /**
     * Serve types this build can dispatch on.
     *
     * Compared case-INSENSITIVELY, matching how the evaluator treats condition logic
     * (`strtolower`). Being marginally more lenient than `$serve->type === 'Fixed'` is
     * deliberate: mis-cased enum values are a separate, pre-existing divergence between
     * the SDKs that `packages/CLAUDE.md` records and explicitly keeps out of the shared
     * vectors, so this must not start dropping entities over it.
     */
    private const SERVE_TYPES = ['fixed', 'rollout'];

    private const CONDITION_LOGIC = ['and', 'or'];

    /**
     * @throws self if $type is present and is not a serve type this build understands
     */
    public static function assertServeType(mixed $type, string $path): void
    {
        self::assertKnown($type, self::SERVE_TYPES, $path, 'serve type');
    }

    /**
     * @throws self if $logic is present and is not a condition logic this build understands
     */
    public static function assertConditionLogic(mixed $logic, string $path): void
    {
        self::assertKnown($logic, self::CONDITION_LOGIC, $path, 'condition logic');
    }

    /**
     * @param string[] $known
     */
    private static function assertKnown(mixed $value, array $known, string $path, string $label): void
    {
        // An absent field is the missing-required-field axis, not this one — every
        // caller already substitutes a default for it, and the SDKs deliberately
        // disagree about what that default is. Checking only values that are PRESENT
        // and unrecognised keeps this change purely additive.
        if (!is_string($value) || $value === '') {
            return;
        }

        if (in_array(strtolower($value), $known, true)) {
            return;
        }

        throw new self(sprintf(
            '%s "%s" is not a %s this SDK version understands',
            $path,
            $value,
            $label,
        ));
    }
}
