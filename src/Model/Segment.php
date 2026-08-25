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
        // An unrecognised conditionLogic would fall to the AND arm in
        // Evaluator::evaluateConditions, silently applying logic the server did not ask
        // for. Drop the segment instead of guessing (#2402). Rules pointing at it then
        // resolve to nothing, which evaluateRule already treats as no-match.
        UnevaluableEntityException::assertConditionLogic(
            $data['conditionLogic'] ?? null,
            'conditionLogic',
        );

        return new self(
            key: RequiredField::string($data, 'key', 'segment'),
            version: $data['version'] ?? 0,
            conditions: array_map(
                fn(array $c) => Condition::fromArray($c),
                $data['conditions'] ?? [],
            ),
            conditionLogic: $data['conditionLogic'] ?? 'and',
        );
    }
}
