<?php

declare(strict_types=1);

namespace Featureflip\Events;

final class Event
{
    /**
     * @param array<string, mixed> $data
     */
    public function __construct(
        public readonly string $type,
        public readonly array $data,
    ) {}

    /**
     * @param array<string, mixed> $context
     */
    public static function evaluation(string $flagKey, array $context, ?string $variationKey): self
    {
        return new self('Evaluation', self::compactPayload([
            'type' => 'Evaluation',
            'flagKey' => $flagKey,
            'userId' => self::userId($context),
            'variation' => $variationKey ?? '',
            'timestamp' => self::nowUtc(),
        ]));
    }

    /**
     * @param array<string, mixed> $context
     * @param array<string, mixed> $metadata
     */
    public static function custom(string $eventKey, array $context, array $metadata = []): self
    {
        return new self('Custom', self::compactPayload([
            'type' => 'Custom',
            'flagKey' => $eventKey,
            'userId' => self::userId($context),
            'timestamp' => self::nowUtc(),
            'metadata' => $metadata,
        ]));
    }

    /**
     * @param array<string, mixed> $context
     */
    public static function identify(array $context): self
    {
        // Strip both identity spellings so the id is carried once, at the top
        // level, and not duplicated inside the attribute bag.
        $attributes = array_diff_key($context, array_flip(['user_id', 'userId']));

        return new self('Identify', self::compactPayload([
            'type' => 'Identify',
            'flagKey' => '$identify',
            'userId' => self::userId($context),
            'timestamp' => self::nowUtc(),
            'metadata' => $attributes,
        ]));
    }

    /**
     * Resolve the event's user identifier from the caller's context.
     *
     * Two rules, both load-bearing:
     *
     * 1. Never throw. A bare `(string)` cast raises \Error for an object with
     *    no __toString and a warning for an array — and warnings are promoted
     *    to ErrorException by Symfony's and Laravel's default error handlers.
     *    Event construction happens after the evaluator's fail-safe guard in
     *    SharedFeatureflipCore::evaluateFlag(), and track()/identify() reach it
     *    with no guard at all, so anything thrown here lands in the host
     *    application's request path (#2259). A value we cannot render degrades
     *    to an empty attribution instead.
     * 2. Accept the `userId` alias. `user_id` is the canonical wire field but
     *    `userId` is an accepted alias, and Evaluator::resolveVariationKey()
     *    honours both when bucketing — so without this a caller who used the
     *    alias was bucketed correctly yet had every event attributed to a blank
     *    user. Resolution order matches the JS SDK's shared core
     *    (`context.user_id ?? context.userId`), and note that a *present* null
     *    falls through to the alias while a present-but-falsy value does not.
     *
     *    The two resolutions are independent and can disagree: the evaluator
     *    reads whichever key `serve.bucketBy` names — defaulting to `userId` —
     *    before falling back, so a context setting BOTH keys to different
     *    values buckets on `userId` and attributes to `user_id`. That mirrors
     *    the JS SDK exactly; don't "align" one half without the other.
     *
     * @param array<string, mixed> $context
     */
    private static function userId(array $context): ?string
    {
        $value = $context['user_id'] ?? $context['userId'] ?? null;

        // No identifier at all is an ordinary anonymous context, not an error.
        // Returning null omits the field entirely, matching the other SDKs; a
        // blank string would claim a present-but-empty identity instead.
        if ($value === null) {
            return null;
        }

        // A backed enum carries a perfectly good identifier on ->value, and is
        // the same shape of caller mistake as passing an entity: worth reading
        // rather than discarding.
        if ($value instanceof \BackedEnum) {
            return (string) $value->value;
        }

        if (is_scalar($value) || $value instanceof \Stringable) {
            return (string) $value;
        }

        // Degrading silently is how a caller ends up with months of
        // blank-attributed analytics and no idea why. Every other degradation
        // in this SDK logs (SharedFeatureflipCore's evaluator and inspector
        // guards); this one does too, in the same format.
        error_log(
            '[featureflip] evaluation context user identifier is not stringable ('
            . get_debug_type($value) . '); the event will carry an empty userId'
        );

        return '';
    }

    /**
     * The current instant as an ISO-8601 string, always in UTC.
     *
     * `new \DateTimeImmutable()` with no explicit zone uses the PROCESS default,
     * so this used to emit whatever `date.timezone` the host's PHP-FPM pool ran
     * in — the only server SDK not sending UTC. That is not a bucketing bug
     * (`format('c')` writes an explicit offset, so the instant is unambiguous
     * and the backend normalises it), but the string is forwarded verbatim to a
     * customer's own analytics provider under the BYO-analytics webhook, and
     * the offset leaks which timezone the caller's infrastructure runs in
     * (#2399).
     *
     * The format matches what SharedFeatureflipCore already stamps on inspector
     * events (`Y-m-d\TH:i:s.v\Z`) — this package had the correct spelling in it
     * the whole time, just not on the path that leaves the process. Milliseconds
     * and a `Z` suffix also line up with the js SDK.
     *
     * Kept as one helper so the three factories cannot drift apart again.
     */
    private static function nowUtc(): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.v\Z');
    }

    /**
     * `userId` and `metadata` are optional on the wire. Omit them rather than
     * sending a null or an empty bag — an empty PHP array would encode as a
     * JSON array (`[]`), which the backend's `Dictionary<string, JsonElement>?`
     * binder rejects outright.
     *
     * @param  array<string, mixed> $data
     * @return array<string, mixed>
     */
    private static function compactPayload(array $data): array
    {
        if (($data['userId'] ?? null) === null) {
            unset($data['userId']);
        }

        if (empty($data['metadata'])) {
            unset($data['metadata']);
        }

        return $data;
    }
}
