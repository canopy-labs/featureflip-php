<?php

declare(strict_types=1);

namespace Featureflip\Model;

final class ConditionGroup
{
    /** @param Condition[] $conditions */
    public function __construct(
        public readonly string $operator,
        public readonly array $conditions,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            operator: $data['operator'] ?? 'and',
            conditions: array_map(
                fn(array $c) => Condition::fromArray($c),
                $data['conditions'] ?? [],
            ),
        );
    }
}
