<?php

declare(strict_types=1);

namespace Featureflip\Model;

final class Condition
{
    /** @param string[] $values */
    public function __construct(
        public readonly string $attribute,
        public readonly string $operator,
        public readonly array $values,
        public readonly bool $negate,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            attribute: $data['attribute'] ?? '',
            operator: $data['operator'] ?? 'equals',
            values: $data['values'] ?? [],
            negate: $data['negate'] ?? false,
        );
    }
}
