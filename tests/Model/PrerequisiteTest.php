<?php

declare(strict_types=1);

namespace Featureflip\Tests\Model;

use Featureflip\Model\Prerequisite;
use PHPUnit\Framework\TestCase;

final class PrerequisiteTest extends TestCase
{
    public function testConstructAssignsCamelCaseFields(): void
    {
        $prereq = new Prerequisite('billing-enabled', 'on');

        $this->assertSame('billing-enabled', $prereq->prerequisiteFlagKey);
        $this->assertSame('on', $prereq->expectedVariationKey);
    }

    public function testFromArrayParsesWireFormat(): void
    {
        $prereq = Prerequisite::fromArray([
            'prerequisiteFlagKey' => 'billing-enabled',
            'expectedVariationKey' => 'on',
        ]);

        $this->assertSame('billing-enabled', $prereq->prerequisiteFlagKey);
        $this->assertSame('on', $prereq->expectedVariationKey);
    }

    public function testFromArrayAcceptsAnEmptyExpectedVariationKey(): void
    {
        $prereq = Prerequisite::fromArray(['prerequisiteFlagKey' => 'p', 'expectedVariationKey' => '']);

        $this->assertSame('', $prereq->expectedVariationKey);
    }

    public function testFromArrayRejectsANonStringExpectedVariationKey(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Prerequisite::fromArray(['prerequisiteFlagKey' => 'p', 'expectedVariationKey' => 5]);
    }
}
