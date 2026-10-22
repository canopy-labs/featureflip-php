<?php

declare(strict_types=1);

namespace Featureflip\Tests\Model;

use Featureflip\Model\ServeConfig;
use PHPUnit\Framework\TestCase;

final class ServeConfigTest extends TestCase
{
    public function testWeightedVariationKeyMayBeEmpty(): void
    {
        $serve = ServeConfig::fromArray([
            'type' => 'Rollout',
            'variations' => [['key' => '', 'weight' => 50], ['key' => 'b', 'weight' => 50]],
        ]);

        $variations = $serve->variations;
        self::assertNotNull($variations);
        self::assertArrayHasKey(0, $variations);
        $this->assertSame('', $variations[0]->key);
    }

    public function testWeightedVariationKeyMustBeAString(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        ServeConfig::fromArray([
            'type' => 'Rollout',
            'variations' => [['key' => 5, 'weight' => 50]],
        ]);
    }
}
