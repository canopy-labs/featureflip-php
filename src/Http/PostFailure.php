<?php

declare(strict_types=1);

namespace Featureflip\Http;

/**
 * Why a {@see HttpClient::post()} failed, and whether sending the same body
 * again could plausibly deliver it.
 *
 * The caller needs both halves. Without the status a 503 is indistinguishable
 * from a delivered batch, which is how evaluation analytics were being lost
 * steadily and invisibly (#2456); without the retryable/permanent split, a
 * rejected SDK key would be retried forever and pin the event queue at its
 * bound, starving every later event.
 *
 * @internal Not part of the public API.
 */
final class PostFailure
{
    public function __construct(
        public readonly string $message,
        /** The response status, or null when no response ever arrived. */
        public readonly ?int $statusCode = null,
    ) {}

    public function isRetryable(): bool
    {
        // No status means the request never produced a response — DNS, TLS, a
        // reset connection, a timeout. Transient by nature, so a later attempt
        // may well get past it.
        if ($this->statusCode === null) {
            return true;
        }

        // 5xx is the case this exists for: the public edge answers the events
        // endpoint with a 503 at a low but constant rate. 429 is the server
        // explicitly asking the caller to come back later.
        //
        // Everything else fails identically next time and must NOT be kept:
        // 401/403 means the SDK key was rejected, 400 means the body is
        // malformed, and a 3xx is a redirect this client does not follow.
        return $this->statusCode >= 500 || $this->statusCode === 429;
    }
}
