<?php

declare(strict_types=1);

namespace Featureflip\Tests\Evaluation;

use Featureflip\Evaluation\CanonicalNumber;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CanonicalNumberTest extends TestCase
{
    /** @return array<string, array{float, string}> */
    public static function spellings(): array
    {
        return [
            '1.0' => [1.0, '1'],
            '1.5' => [1.5, '1.5'],
            '0.1' => [0.1, '0.1'],
            '0.1+0.2' => [0.30000000000000004, '0.30000000000000004'],
            '-0.0' => [-0.0, '0'],
            '100.25' => [100.25, '100.25'],
            '1e21' => [1e21, '1e+21'],
            '1.5e21' => [1.5e21, '1.5e+21'],
            '1e20' => [1e20, '100000000000000000000'],
            '1e-7' => [1e-7, '1e-7'],
            '0.000001' => [0.000001, '0.000001'],
            '2.5e-7' => [2.5e-7, '2.5e-7'],
            '123456.789' => [123456.789, '123456.789'],
            '-42.5' => [-42.5, '-42.5'],
            '2^-44' => [5.684341886080802e-14, '5.684341886080802e-14'],
            '1e23' => [1e23, '1e+23'],
            'min denormal' => [5e-324, '5e-324'],
            'max' => [1.7976931348623157e308, '1.7976931348623157e+308'],
            'NaN' => [NAN, 'NaN'],
            'inf' => [INF, 'Infinity'],
            '-inf' => [-INF, '-Infinity'],
        ];
    }

    #[DataProvider('spellings')]
    public function testSpelling(float $x, string $expected): void
    {
        $this->assertSame($expected, CanonicalNumber::format($x));
    }
}
