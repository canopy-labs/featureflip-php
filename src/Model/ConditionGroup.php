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

    public static function fromArray(array $data, string $path = 'conditionGroup'): self
    {
        // Same enum as Segment::conditionLogic, different carrier: this one lives on a
        // rule, so the entity dropped is the FLAG (#2402).
        UnevaluableEntityException::assertConditionLogic(
            $data['operator'] ?? null,
            $path . '.operator',
        );

        return new self(
            operator: $data['operator'] ?? 'and',
            conditions: array_map(
                fn(array $c) => Condition::fromArray($c),
                $data['conditions'] ?? [],
            ),
        );
    }
}
