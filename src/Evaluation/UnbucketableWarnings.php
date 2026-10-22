<?php

declare(strict_types=1);

namespace Featureflip\Evaluation;

use Psr\Log\LoggerInterface;

/**
 * Process-wide, deduplicated warning for a percentage rollout that was skipped
 * because the context has no value for its bucket-by attribute (#4236).
 *
 * Logs once per (flag key, rule id or "fallthrough", bucketBy). The set is capped
 * at {@see self::MAX_KEYS}; once full, unseen keys are dropped silently. Only the
 * flag key, rule id and bucket-by attribute NAME are ever logged, never a
 * context value. PHP has no shared-memory threads in the request model, so a
 * plain static array needs no lock.
 *
 * @internal
 */
final class UnbucketableWarnings
{
    public const MAX_KEYS = 1000;

    /** @var array<string, true> */
    private static array $seen = [];

    public static function warn(LoggerInterface $logger, string $flagKey, ?string $ruleId, string $bucketBy): void
    {
        $where = $ruleId === null ? 'fallthrough' : 'rule ' . $ruleId;
        $key = json_encode([$flagKey, $where, $bucketBy], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($key === false || isset(self::$seen[$key]) || count(self::$seen) >= self::MAX_KEYS) {
            return;
        }
        self::$seen[$key] = true;

        $logger->warning(sprintf(
            '[featureflip] Skipped the percentage rollout in flag "%s" (%s): the context has no value for "%s". '
            . 'Pass a stable id in that attribute to include this context in the rollout.',
            $flagKey,
            $where,
            $bucketBy,
        ));
    }

    /** Number of distinct keys recorded so far. */
    public static function count(): int
    {
        return count(self::$seen);
    }

    /** Clears the dedup set. For tests. */
    public static function reset(): void
    {
        self::$seen = [];
    }
}
