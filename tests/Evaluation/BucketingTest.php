<?php

declare(strict_types=1);

namespace Featureflip\Tests\Evaluation;

use Featureflip\Evaluation\Bucketing;
use PHPUnit\Framework\TestCase;

final class BucketingTest extends TestCase
{
    public function testBucketReturnsDeterministicValue(): void
    {
        $bucket1 = Bucketing::bucket('salt', 'user-123');
        $bucket2 = Bucketing::bucket('salt', 'user-123');
        $this->assertSame($bucket1, $bucket2);
    }

    public function testBucketRange(): void
    {
        $bucket = Bucketing::bucket('test-salt', 'test-value');
        $this->assertGreaterThanOrEqual(0, $bucket);
        $this->assertLessThan(100, $bucket);
    }

    public function testDifferentInputsProduceDifferentBuckets(): void
    {
        $bucket1 = Bucketing::bucket('salt', 'user-1');
        $bucket2 = Bucketing::bucket('salt', 'user-2');
        $this->assertNotSame($bucket1, $bucket2);
    }

    public function testDistributionIsReasonable(): void
    {
        $buckets = array_fill(0, 100, 0);
        for ($i = 0; $i < 10000; $i++) {
            $bucket = Bucketing::bucket('test', "user-$i");
            $buckets[$bucket]++;
        }
        foreach ($buckets as $count) {
            $this->assertGreaterThan(30, $count);
            $this->assertLessThan(200, $count);
        }
    }

    public function testMatchesKnownMd5Output(): void
    {
        $bucket = Bucketing::bucket('salt', 'value');
        $hash = md5('salt:value', true);
        $expected = unpack('V', substr($hash, 0, 4))[1] % 100;
        $this->assertSame($expected, $bucket);
    }
}
