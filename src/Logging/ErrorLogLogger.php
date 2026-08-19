<?php

declare(strict_types=1);

namespace Featureflip\Logging;

use Psr\Log\AbstractLogger;

/**
 * The logger the SDK uses when the caller supplies none.
 *
 * Writes to PHP's error log, matching the format the evaluator and inspector
 * guards in SharedFeatureflipCore already use. It exists so that "the caller
 * didn't configure logging" never degrades to "the SDK says nothing at all" —
 * a silently-empty flag store is indistinguishable from a working one, which is
 * how #2258 stayed invisible.
 *
 * Prefer passing a real PSR-3 logger via {@see \Featureflip\Config::$logger}:
 * in a Laravel or Symfony application this lands in the PHP error log rather
 * than the application log, where nobody is looking.
 */
final class ErrorLogLogger extends AbstractLogger
{
    /**
     * @param mixed                $level
     * @param array<string, mixed> $context
     */
    public function log($level, string|\Stringable $message, array $context = []): void
    {
        error_log('[featureflip] ' . $message);
    }
}
