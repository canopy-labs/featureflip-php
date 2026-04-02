<?php

declare(strict_types=1);

namespace Featureflip;

final class EvaluationDetail
{
    public function __construct(
        public readonly mixed $value,
        public readonly string $reason,
        public readonly ?string $ruleId = null,
        public readonly ?string $variationKey = null,
    ) {}
}
