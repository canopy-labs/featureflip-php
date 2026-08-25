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

    public static function fromArray(array $data, string $path = 'serve'): self
    {
        // An unrecognised serve type would fall to the ROLLOUT arm in
        // Evaluator::resolveVariationKey and bucket against weights that may not exist.
        // Drop the containing entity instead of guessing (#2402).
        UnevaluableEntityException::assertServeType($data['type'] ?? null, $path . '.type');

        return new self(
            type: $data['type'] ?? 'Fixed',
            variation: $data['variation'] ?? null,
            bucketBy: $data['bucketBy'] ?? null,
            salt: $data['salt'] ?? null,
            variations: isset($data['variations'])
                ? array_map(
                    fn(array $v) => new WeightedVariation(
                        RequiredField::string($v, 'key', 'weighted variation'),
                        RequiredField::int($v, 'weight', 'weighted variation'),
                    ),
                    $data['variations'],
                )
                : null,
        );
    }
}
