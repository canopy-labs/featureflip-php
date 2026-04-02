<?php

declare(strict_types=1);

namespace Featureflip\Model;

final class WeightedVariation
{
    public function __construct(
        public readonly string $key,
        public readonly int $weight,
    ) {}
}
