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

    public static function get(string $sdkKey, ?Config $config = null): self
    {
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
        $detail = $this->core->evaluateFlag($key, $context, $default);
        $value = is_bool($detail->value) ? $detail->value : $default;
        $this->notifyInspectors($key, $context, $detail, $value);

        return $value;
    }

    /**
     * @param array<string, mixed> $context
     */
    public function stringVariation(string $key, array $context, string $default): string
    {
        $detail = $this->core->evaluateFlag($key, $context, $default);
        $value = is_string($detail->value) ? $detail->value : $default;
        $this->notifyInspectors($key, $context, $detail, $value);

        return $value;
    }

    /**
     * @param array<string, mixed> $context
     */
    public function numberVariation(string $key, array $context, int|float $default): int|float
    {
        $detail = $this->core->evaluateFlag($key, $context, $default);
        $value = is_int($detail->value) || is_float($detail->value) ? $detail->value : $default;
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
        $detail = $this->core->evaluateFlag($key, $context, $default);
        $value = is_array($detail->value) ? $detail->value : $default;
        $this->notifyInspectors($key, $context, $detail, $value);

        return $value;
    }

    /**
     * @param array<string, mixed> $context
     */
    public function variationDetail(string $key, array $context, mixed $default): EvaluationDetail
    {
        $detail = $this->core->evaluateFlag($key, $context, $default);
        // No type guard here — the caller gets the detail verbatim, so the
        // event reports the detail's own value.
        $this->notifyInspectors($key, $context, $detail, $detail->value);

        return $detail;
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
        $this->core->track($eventKey, $context, $metadata);
    }

    /**
     * @param array<string, mixed> $context
     */
    public function identify(array $context): void
    {
        $this->core->identify($context);
    }

    public function flush(): void
    {
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
