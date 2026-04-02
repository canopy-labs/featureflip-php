<?php

declare(strict_types=1);

namespace Featureflip\Model;

final class Variation
{
    public function __construct(
        public readonly string $key,
        public readonly mixed $value,
    ) {}
}
