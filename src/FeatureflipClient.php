<?php

declare(strict_types=1);

namespace Featureflip;

final class FeatureflipClient
{
    /** @var array<string, SharedFeatureflipCore> */
    private static array $cores = [];

    private bool $closed = false;

    private function __construct(
        private readonly SharedFeatureflipCore $core,
    ) {}

    /**
     * @param string $sdkKey Falls back to the `FEATUREFLIP_SDK_KEY` environment
     *                       variable when empty, matching python, go, csharp
     *                       and ruby.
     */
    public static function get(string $sdkKey, ?Config $config = null): self
    {
        // Resolved BEFORE the cache lookup: the core is keyed by SDK key, so
        // resolving afterwards would give a caller who passes '' a different
        // core from one who names the same key explicitly.
        $sdkKey = self::resolveSdkKey($sdkKey);

        if (isset(self::$cores[$sdkKey])) {
            $existing = self::$cores[$sdkKey];
            if ($existing->acquire()) {
                return new self($existing);
            }
            // Core is dead — remove stale entry and fall through
            unset(self::$cores[$sdkKey]);
        }

        if ($config === null) {
            throw new \InvalidArgumentException(
                'Config is required when creating a new FeatureflipClient instance'
            );
        }

        $core = SharedFeatureflipCore::create($sdkKey, $config);
        self::$cores[$sdkKey] = $core;

        return new self($core);
    }

    /**
     * Resolve the SDK key from the argument, then the environment.
     *
     * `FEATUREFLIP_SDK_KEY` has been advertised in the README since the SDK
     * shipped while nothing read it. The convention is real — python, go,
     * csharp and ruby all implement this exact fallback — so PHP was the
     * outlier, not the documentation (#2261).
     */
    private static function resolveSdkKey(string $sdkKey): string
    {
        if ($sdkKey !== '') {
            return $sdkKey;
        }

        $fromEnvironment = getenv('FEATUREFLIP_SDK_KEY');
        if (is_string($fromEnvironment) && $fromEnvironment !== '') {
            return $fromEnvironment;
        }

        throw new \InvalidArgumentException(
            'An SDK key is required: pass it to FeatureflipClient::get() or set FEATUREFLIP_SDK_KEY',
        );
    }

    /**
     * @param array<string, mixed> $flags
     * @param array<mixed>         $inspectors Evaluation inspectors, same shape as
     *                                         {@see Config::$inspectors}. Test-stub
     *                                         clients notify them exactly like live
     *                                         ones, so a unit test can assert on the
     *                                         events its inspector receives.
     */
    public static function forTesting(array $flags, array $inspectors = []): self
    {
        return new self(SharedFeatureflipCore::createForTesting($flags, $inspectors));
    }

    /**
     * @internal
     */
    public static function resetForTesting(): void
    {
        foreach (self::$cores as $core) {
            $core->shutdown();
        }
        self::$cores = [];
    }

    /**
     * A closed handle is inert.
     *
     * `close()` used to mean three different things depending on which method
     * you called: inspectors and refresh() were suppressed, while the variation
     * accessors, track(), identify() and flush() carried on — still reading the
     * shut-down core's store, still reaching the network (#2267). One rule now:
     * after close(), variation calls return the caller's default and everything
     * else no-ops. Inertness is per HANDLE, not per core, so closing one handle
     * never disables a sibling still holding the same core.
     *
     * Every public variation accessor below owns its own inspector
     * notification: it evaluates once, applies its type guard, then notifies
     * once with the guarded value. No accessor delegates to another, so an
     * evaluation produces exactly one event — never zero, never two. An
     * unexpected evaluator error fails safe inside evaluateFlag() to an ERROR
     * detail carrying the default, so even a throwing evaluation still emits
     * exactly one (ERROR) event.
     *
     * @param array<string, mixed> $context
     */
    public function boolVariation(string $key, array $context, bool $default): bool
    {
        if ($this->closed) {
            return $default;
        }

        $detail = $this->core->evaluateFlag($key, $context, $default);
        [$detail, $value] = $this->narrow($detail, is_bool(...), $default);
        $this->notifyInspectors($key, $context, $detail, $value);

        return $value;
    }

    /**
     * @param array<string, mixed> $context
     */
    public function stringVariation(string $key, array $context, string $default): string
    {
        if ($this->closed) {
            return $default;
        }

        $detail = $this->core->evaluateFlag($key, $context, $default);
        [$detail, $value] = $this->narrow($detail, is_string(...), $default);
        $this->notifyInspectors($key, $context, $detail, $value);

        return $value;
    }

    /**
     * @param array<string, mixed> $context
     */
    public function numberVariation(string $key, array $context, int|float $default): int|float
    {
        if ($this->closed) {
            return $default;
        }

        $detail = $this->core->evaluateFlag($key, $context, $default);
        [$detail, $value] = $this->narrow(
            $detail,
            static fn (mixed $v): bool => is_int($v) || is_float($v),
            $default,
        );
        $this->notifyInspectors($key, $context, $detail, $value);

        return $value;
    }

    /**
     * @param array<string, mixed> $context
     * @param array<string, mixed> $default
     * @return array<string, mixed>
     */
    public function jsonVariation(string $key, array $context, array $default): array
    {
        if ($this->closed) {
            return $default;
        }

        $detail = $this->core->evaluateFlag($key, $context, $default);
        [$detail, $value] = $this->narrow($detail, is_array(...), $default);
        $this->notifyInspectors($key, $context, $detail, $value);

        return $value;
    }

    /**
     * @param array<string, mixed> $context
     */
    public function variationDetail(string $key, array $context, mixed $default): EvaluationDetail
    {
        // ERROR rather than a new PHP-only reason: the reason vocabulary is a
        // cross-SDK contract, and ERROR already means "you got your default
        // because something prevented a real evaluation".
        if ($this->closed) {
            return new EvaluationDetail($default, 'ERROR');
        }

        $detail = $this->core->evaluateFlag($key, $context, $default);
        // No type guard here — the caller gets the detail verbatim, so the
        // event reports the detail's own value.
        $this->notifyInspectors($key, $context, $detail, $detail->value);

        return $detail;
    }

    /**
     * Narrows a served value to the type the calling accessor requires.
     *
     * On a mismatch the caller's default is substituted AND the reason becomes
     * ERROR, so a type-mismatched read is detectable rather than looking like a
     * healthy serve (#2281). Substituting the value alone -- the prior behaviour --
     * meant a caller reading a string flag through boolVariation() silently got
     * their default back under FALLTHROUGH.
     *
     * EvaluationDetail is readonly, so the mismatch case rebuilds it. The
     * variation/rule/prerequisite keys are preserved: the flag config is healthy,
     * the caller simply asked for the wrong type.
     *
     * variationDetail() deliberately does not use this -- it takes a mixed default
     * and so has no requested type to check against.
     *
     * @param callable(mixed): bool $matches
     * @return array{EvaluationDetail, mixed}
     */
    private function narrow(EvaluationDetail $detail, callable $matches, mixed $default): array
    {
        if ($matches($detail->value)) {
            return [$detail, $detail->value];
        }

        return [
            new EvaluationDetail(
                $default,
                'ERROR',
                $detail->ruleId,
                $detail->variationKey,
                $detail->prerequisiteKey,
            ),
            $default,
        ];
    }

    /**
     * Notify inspectors for one completed variation call, unless this handle is
     * already closed — a closed client evaluates as before but emits no
     * inspector events (matching the Python and Ruby SDKs).
     *
     * @param array<string, mixed> $context
     */
    private function notifyInspectors(string $key, array $context, EvaluationDetail $detail, mixed $value): void
    {
        if ($this->closed) {
            return;
        }

        $this->core->notifyInspectors($key, $context, $detail, $value);
    }

    /**
     * @param array<string, mixed> $context
     * @param array<string, mixed> $metadata
     */
    public function track(string $eventKey, array $context, array $metadata = []): void
    {
        if ($this->closed) {
            return;
        }

        $this->core->track($eventKey, $context, $metadata);
    }

    /**
     * @param array<string, mixed> $context
     */
    public function identify(array $context): void
    {
        if ($this->closed) {
            return;
        }

        $this->core->identify($context);
    }

    /**
     * Whether the SDK holds a flag configuration.
     *
     * `false` means every evaluation is currently returning the default you
     * pass, because nothing was ever loaded — a rejected SDK key, or an
     * evaluation API that was unreachable with no cached snapshot to fall back
     * on. Useful for a readiness probe or a degraded-mode branch; the SDK
     * itself reports the condition to the log either way.
     *
     * `true` covers a configuration that is stale but retained: an outage does
     * not make the SDK uninitialised, it makes it out of date.
     *
     * A closed handle always reports `false`, like every other accessor on a
     * closed handle. Closing one handle does not affect a sibling.
     */
    public function isInitialized(): bool
    {
        if ($this->closed) {
            return false;
        }

        return $this->core->isInitialized();
    }

    /**
     * Refresh the flag configuration now if it is due one.
     *
     * Optional — the evaluation path already refreshes by itself. This exists
     * for persistent workers (Laravel Octane, RoadRunner, FrankenPHP, Swoole)
     * that would rather the fetch happened *between* requests than inside one:
     *
     * ```php
     * Octane::tick('featureflip', fn () => $client->refresh())->seconds(10);
     * ```
     *
     * Honours the poll interval and the failure backoff exactly as the
     * automatic path does, so calling it on a tight tick costs nothing when
     * there is nothing to do.
     */
    public function refresh(): void
    {
        if ($this->closed) {
            return;
        }

        $this->core->refresh();
    }

    public function flush(): void
    {
        if ($this->closed) {
            return;
        }

        $this->core->flush();
    }

    public function close(): void
    {
        if ($this->closed) {
            return;
        }

        $this->closed = true;

        if ($this->core->release()) {
            $this->core->shutdown();
            self::$cores = array_filter(
                self::$cores,
                fn (SharedFeatureflipCore $c): bool => $c !== $this->core,
            );
        }
    }
}
