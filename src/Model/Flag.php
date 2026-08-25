<?php

declare(strict_types=1);

namespace Featureflip\Model;

final class Flag
{
    /**
     * @param Variation[] $variations
     * @param Rule[] $rules
     * @param Prerequisite[] $prerequisites
     */
    public function __construct(
        public readonly string $key,
        public readonly int $version,
        public readonly string $type,
        public readonly bool $enabled,
        public readonly array $variations,
        public readonly array $rules,
        public readonly ?ServeConfig $fallthrough,
        public readonly ?string $offVariation,
        public readonly array $prerequisites = [],
    ) {}

    public function getVariation(string $key): ?Variation
    {
        foreach ($this->variations as $variation) {
            if ($variation->key === $key) {
                return $variation;
            }
        }
        return null;
    }

    public static function fromArray(array $data): self
    {
        return new self(
            key: RequiredField::string($data, 'key', 'flag'),
            version: $data['version'] ?? 0,
            type: $data['type'] ?? 'boolean',
            enabled: $data['enabled'] ?? false,
            variations: array_map(
                fn(array $v) => new Variation(RequiredField::string($v, 'key', 'flag variation'), $v['value'] ?? null),
                $data['variations'] ?? [],
            ),
            rules: array_map(
                fn(array $r) => Rule::fromArray($r),
                $data['rules'] ?? [],
            ),
            fallthrough: isset($data['fallthrough']) ? ServeConfig::fromArray($data['fallthrough'], 'fallthrough') : null,
            offVariation: $data['offVariation'] ?? null,
            prerequisites: array_map(
                fn(array $p) => Prerequisite::fromArray($p),
                $data['prerequisites'] ?? [],
            ),
        );
    }
}
