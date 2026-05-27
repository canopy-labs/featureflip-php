<?php

declare(strict_types=1);

namespace Featureflip\Model;

final class Prerequisite
{
    public function __construct(
        public readonly string $prerequisiteFlagKey,
        public readonly string $expectedVariationKey,
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            prerequisiteFlagKey: $data['prerequisiteFlagKey'],
            expectedVariationKey: $data['expectedVariationKey'],
        );
    }
}
