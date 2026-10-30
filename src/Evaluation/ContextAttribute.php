<?php

declare(strict_types=1);

namespace Featureflip\Evaluation;

/**
 * Looks an attribute up in an evaluation context map.
 *
 * `user_id` is the canonical spelling of the user identifier and `userId` is
 * accepted as an alias (#4518). A lookup of exactly one of the two resolves
 * `user_id` when it is non-null, else `userId` when it is non-null, else null.
 * An empty string is a value and does not fall through. Every other key,
 * including other casings, is an ordinary attribute.
 *
 * @internal
 */
final class ContextAttribute
{
    /**
     * @param array<string, mixed> $context
     */
    public static function resolve(array $context, string $attribute): mixed
    {
        if ($attribute === 'user_id' || $attribute === 'userId') {
            return $context['user_id'] ?? $context['userId'] ?? null;
        }

        return $context[$attribute] ?? null;
    }
}
