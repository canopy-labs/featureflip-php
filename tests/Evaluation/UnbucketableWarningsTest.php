<?php

declare(strict_types=1);

namespace Featureflip\Tests\Evaluation;

use Featureflip\Evaluation\UnbucketableWarnings;
use PHPUnit\Framework\TestCase;

final class UnbucketableWarningsTest extends TestCase
{
    protected function setUp(): void
    {
        UnbucketableWarnings::reset();
    }

    protected function tearDown(): void
    {
        UnbucketableWarnings::reset();
    }

    public function testDedupsPerFlagWhereAndBucketBy(): void
    {
        $logger = new CollectingLogger();
        UnbucketableWarnings::warn($logger, 'f', 'r1', 'userId');
        UnbucketableWarnings::warn($logger, 'f', 'r1', 'userId');
        UnbucketableWarnings::warn($logger, 'f', 'r1', 'other');
        UnbucketableWarnings::warn($logger, 'f', null, 'userId');
        $this->assertCount(3, $logger->messages);
        $this->assertStringContainsString('(fallthrough)', $logger->messages[2]);
    }

    public function testStopsRecordingAndWarningAfterCap(): void
    {
        $logger = new CollectingLogger();
        for ($i = 0; $i < 1000; $i++) {
            UnbucketableWarnings::warn($logger, "cap-$i", 'r1', 'userId');
        }
        $this->assertCount(1000, $logger->messages);

        UnbucketableWarnings::warn($logger, 'cap-overflow', 'r1', 'userId');
        UnbucketableWarnings::warn($logger, 'cap-overflow', 'r1', 'userId');
        $this->assertCount(1000, $logger->messages);
        $this->assertSame(1000, UnbucketableWarnings::count());
    }
}
