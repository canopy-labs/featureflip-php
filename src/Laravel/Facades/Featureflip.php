<?php

declare(strict_types=1);

namespace Featureflip\Laravel\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static bool boolVariation(string $key, array<string, mixed> $context, bool $default)
 * @method static string stringVariation(string $key, array<string, mixed> $context, string $default)
 * @method static int|float numberVariation(string $key, array<string, mixed> $context, int|float $default)
 * @method static array<mixed> jsonVariation(string $key, array<string, mixed> $context, array<mixed> $default)
 * @method static \Featureflip\EvaluationDetail variationDetail(string $key, array<string, mixed> $context, mixed $default)
 * @method static void track(string $eventKey, array<string, mixed> $context, array<string, mixed> $metadata = [])
 * @method static void identify(array<string, mixed> $context)
 * @method static bool isInitialized()
 * @method static void refresh()
 * @method static void flush()
 *
 * @see \Featureflip\FeatureflipClient
 */
final class Featureflip extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Featureflip\FeatureflipClient::class;
    }
}
