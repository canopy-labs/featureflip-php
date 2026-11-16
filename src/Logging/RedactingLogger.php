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
        $text = $this->redact((string) $message);

        foreach ($context as $name => $value) {
            $context[$name] = $this->redactValue($value);
        }

        $this->inner->log($level, $text, $context);
    }

    private function active(): bool
    {
        return strlen($this->secret) >= self::MIN_REDACTABLE_LENGTH;
    }

    private function redact(string $text): string
    {
        return $this->active() ? str_replace($this->secret, self::REPLACEMENT, $text) : $text;
    }

    /**
     * PSR-3 context values reach the host's sink too (`['exception' => $e]` is
     * the convention). Strings are redacted; a Throwable that quotes the key in
     * its message, any `previous` link, or its trace is replaced by a
     * RuntimeException carrying the redacted "Class: message" (and a redacted
     * previous chain), since an exception cannot be rewritten in place. Clean
     * Throwables pass through by identity.
     */
    private function redactValue(mixed $value): mixed
    {
        if (is_string($value)) {
            return $this->redact($value);
        }

        if (is_array($value)) {
            return array_map($this->redactValue(...), $value);
        }

        if ($value instanceof \Throwable && $this->active() && $this->isDirty($value)) {
            return $this->substitute($value);
        }

        return $value;
    }

    private function isDirty(\Throwable $e): bool
    {
        for (; $e !== null; $e = $e->getPrevious()) {
            if (
                str_contains($e->getMessage(), $this->secret)
                || $this->traceHoldsSecret($e->getTrace())
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Trace args are only present when `zend.exception_ignore_args=0`. The
     * string rendering truncates args, so walk the raw frames instead.
     *
     * @param array<mixed> $frames
     */
    private function traceHoldsSecret(array $frames): bool
    {
        foreach ($frames as $value) {
            if (is_string($value) && str_contains($value, $this->secret)) {
                return true;
            }
            if (is_array($value) && $this->traceHoldsSecret($value)) {
                return true;
            }
        }

        return false;
    }

    private function substitute(\Throwable $e): \RuntimeException
    {
        $previous = $e->getPrevious();

        return new \RuntimeException(
            $this->redact($e::class . ': ' . $e->getMessage()),
            0,
            $previous === null ? null : $this->substitute($previous),
        );
    }
}
