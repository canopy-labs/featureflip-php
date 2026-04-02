<?php

declare(strict_types=1);

namespace Featureflip\Model;

final class ServeConfig
{
    /** @param WeightedVariation[]|null $variations */
    public function __construct(
        public readonly string $type,
        public readonly ?string $variation,
        public readonly ?string $bucketBy,
        public readonly ?string $salt,
        public readonly ?array $variations,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            type: $data['type'] ?? 'Fixed',
            variation: $data['variation'] ?? null,
            bucketBy: $data['bucketBy'] ?? null,
            salt: $data['salt'] ?? null,
            variations: isset($data['variations'])
                ? array_map(
                    fn(array $v) => new WeightedVariation($v['key'], $v['weight']),
                    $data['variations'],
                )
                : null,
        );
    }
}
