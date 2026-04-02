<?php

declare(strict_types=1);

namespace Featureflip\Model;

final class Rule
{
    /** @param ConditionGroup[] $conditionGroups */
    public function __construct(
        public readonly string $id,
        public readonly int $priority,
        public readonly array $conditionGroups,
        public readonly ServeConfig $serve,
        public readonly ?string $segmentKey,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            id: $data['id'] ?? '',
            priority: $data['priority'] ?? 0,
            conditionGroups: array_map(
                fn(array $g) => ConditionGroup::fromArray($g),
                $data['conditionGroups'] ?? [],
            ),
            serve: ServeConfig::fromArray($data['serve'] ?? []),
            segmentKey: $data['segmentKey'] ?? null,
        );
    }
}
