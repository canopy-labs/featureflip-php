<?php

declare(strict_types=1);

namespace Featureflip\Model;

/**
 * Validates the wire fields a model cannot do without.
 *
 * These reads used to be bare subscripts (`$data['key']`, `$v['weight']`),
 * which meant a malformed payload surfaced as a PHP warning followed by a
 * TypeError about a constructor argument — noisy in the host's error log, and
 * describing the symptom rather than the payload. Rejecting explicitly keeps
 * Poller's per-entry skip honest: the entry is dropped for a stated reason,
 * with nothing leaking into the host's error handler on the way (#2258).
 *
 * @internal
 */
final class RequiredField
{
    /**
     * @param array<string, mixed> $data
     */
    public static function string(array $data, string $key, string $context): string
    {
        $value = $data[$key] ?? null;

        if (!is_string($value) || $value === '') {
            throw new \InvalidArgumentException($context . ' is missing a usable "' . $key . '"');
        }

        return $value;
    }

    /**
     * Accepts any numeric form. The evaluation API sends JSON integers, but
     * tolerating `50.0` or `"50"` costs nothing and drops a whole class of
     * spurious rejection.
     *
     * @param array<string, mixed> $data
     */
    public static function int(array $data, string $key, string $context): int
    {
        $value = $data[$key] ?? null;

        if (!is_numeric($value)) {
            throw new \InvalidArgumentException($context . ' is missing a usable "' . $key . '"');
        }

        return (int) $value;
    }
}
