<?php

declare(strict_types=1);

namespace Featureflip\Model;

final class Segment
{
    /** @param Condition[] $conditions */
    public function __construct(
        public readonly string $key,
        public readonly int $version,
        public readonly array $conditions,
        public readonly string $conditionLogic,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            key: $data['key'],
            version: $data['version'] ?? 0,
            conditions: array_map(
                fn(array $c) => Condition::fromArray($c),
                $data['conditions'] ?? [],
            ),
            conditionLogic: $data['conditionLogic'] ?? 'and',
        );
    }
}
