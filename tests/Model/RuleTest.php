<?php

declare(strict_types=1);

namespace Featureflip\Tests\Model;

use Featureflip\Model\Rule;
use PHPUnit\Framework\TestCase;

final class RuleTest extends TestCase
{
    public function testFromArrayWithMissingServeKeyDoesNotCrash(): void
    {
        $data = [
            'id' => 'rule-1',
            'priority' => 1,
            'conditions' => [],
            'conditionLogic' => 'and',
            // 'serve' key intentionally omitted
        ];

        $rule = Rule::fromArray($data);

        $this->assertSame('rule-1', $rule->id);
        $this->assertSame('Fixed', $rule->serve->type);
        $this->assertNull($rule->serve->variation);
    }
}
