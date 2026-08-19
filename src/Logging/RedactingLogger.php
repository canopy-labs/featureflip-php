<?php

declare(strict_types=1);

namespace Featureflip\Logging;

use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;

/**
 * Strips the SDK key out of anything the SDK logs.
 *
 * Failure paths report the underlying exception verbatim, because the whole
 * point of #2258 was that a config-load failure must not be silent. But that
 * exception comes from a PSR-18 client the *caller* supplied, and the SDK sets
 * the key as the `Authorization` header on every request — so a client that
 * quotes request headers in its exception message would hand the credential to
 * whatever the host logs to. Guzzle happens not to; the SDK does not get to
 * choose which client it is given (#2266).
 *
 * Applied as a decorator around the resolved logger rather than at each call
 * site, so it covers the eleven that exist today and any added later, and so
 * the default {@see ErrorLogLogger} is covered too — PHP's error log is no
 * safer a place for a credential than an aggregator.
 *
 * @internal Not part of the public API.
 */
final class RedactingLogger extends AbstractLogger
{
    private const REPLACEMENT = '[redacted]';

    /**
     * Below this, the value is not treated as a credential.
     *
     * A real key is `sdk_server_`/`sdk_client_` followed by 43 base64url
     * characters — 54 in total, always (see the Management API's
     * SdkKeyGeneratorService: 32 random bytes). Redacting a value far shorter
     * than that corrupts every log line it happens to appear inside: with the
     * key `k`, "skipped a malformed flag" becomes "s[redacted]ipped a
     * malformed flag". The documented trade-off is that a string too short to
     * be a Featureflip key is left alone — such a key cannot authenticate
     * anyway, so there is no credential to protect.
     */
    private const MIN_REDACTABLE_LENGTH = 20;

    public function __construct(
        private readonly LoggerInterface $inner,
        private readonly string $secret,
    ) {}

    /**
     * @param mixed                $level
     * @param array<string, mixed> $context
     */
    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $text = (string) $message;

        if (strlen($this->secret) >= self::MIN_REDACTABLE_LENGTH) {
            $text = str_replace($this->secret, self::REPLACEMENT, $text);
        }

        $this->inner->log($level, $text, $context);
    }
}
