<?php

declare(strict_types=1);

namespace Featureflip\Tests\Evaluation;

use Featureflip\Evaluation\ConditionEvaluator;
use Featureflip\Model\Condition;
use PHPUnit\Framework\TestCase;

/**
 * A date operand that matches the ISO grammar but names no real calendar day must match
 * NOTHING, as it does in the engine, csharp, go, python and java (#2491).
 *
 * #2480 converged the seven SDKs on one ISO grammar, but a character class cannot express
 * "is a real day": "2024-02-30" matches \d{4}-\d{2}-\d{2} everywhere, so the grammar guard
 * is silent on it. Three SDKs then ROLLED IT OVER -- php, js and ruby all resolved it to
 * 2024-03-01 -- while the engine and the other four rejected it. A flag therefore served
 * different variations to two users purely by which SDK their service used, off one saved
 * rule.
 *
 * php diverged in one shape the other two did not, and which #2491 did not report: a zero
 * month or zero day rolled BACKWARDS into the previous year, so "2024-00-01" resolved to
 * 2023-12-01 and "2024-01-00" to 2023-12-31.
 *
 * This is one of the few cross-SDK date questions where the engine is NOT the outlier, so
 * the fix moves php TOWARD it, and the expectations are engine-generated in the shared
 * golden vectors (c-date-unreal-*) rather than hand-authored.
 *
 * The rollover was invisible to any suite that only asserted parseability: the operand
 * parses fine, just to the wrong instant. These assert the OUTCOME of a comparison that
 * inverts across the month boundary instead.
 */
final class DateUnrealDayTest extends TestCase
{
    private ConditionEvaluator $evaluator;

    protected function setUp(): void
    {
        $this->evaluator = new ConditionEvaluator();
    }

    private function dateMatches(string $attr, string $operator, string $target): bool
    {
        $condition = new Condition('attr', $operator, [$target], false);

        return $this->evaluator->evaluate($condition, ['attr' => $attr]);
    }

    /**
     * An operand that parses to SOME instant satisfies exactly one of these; one that
     * parses to nothing satisfies neither. Comparing in both directions AND on both sides
     * of the condition is what separates "unparseable" from "parsed to an extreme instant"
     * -- a single assertion cannot, and a rolled-over date is a perfectly ordinary instant.
     */
    private function unparseable(string $operand): bool
    {
        return !$this->dateMatches($operand, 'After', '0')
            && !$this->dateMatches($operand, 'Before', '0')
            && !$this->dateMatches('0', 'After', $operand)
            && !$this->dateMatches('0', 'Before', $operand);
    }

    /**
     * The reported class: the day is within 01-31 so the grammar admits it, but the month
     * is shorter than that. All three rolling SDKs resolved these to the 1st of the month
     * after.
     *
     * Plus the century rule -- divisible by 100 but not 400 is NOT a leap year, the case a
     * naive `year % 4 === 0` check accepts -- and the structurally out-of-range and
     * zero-valued shapes, pinned so the explicit check that now replaces each runtime's
     * incidental rejection cannot silently widen or narrow it.
     *
     * @return array<string, array{string}>
     */
    public static function unrealDayProvider(): array
    {
        return [
            'February 30 in a leap year' => ['2024-02-30'],
            'February 31 in a leap year' => ['2024-02-31'],
            'February 29 in a NON-leap year' => ['2023-02-29'],
            'February 30 in a non-leap year' => ['2023-02-30'],
            'April has 30 days' => ['2024-04-31'],
            'June has 30 days' => ['2024-06-31'],
            'September has 30 days' => ['2024-09-31'],
            'November has 30 days' => ['2024-11-31'],
            '1900 is divisible by 100 but not 400' => ['1900-02-29'],
            '1800 is divisible by 100 but not 400' => ['1800-02-29'],
            '2100 is divisible by 100 but not 400' => ['2100-02-29'],
            '2200 is divisible by 100 but not 400' => ['2200-02-29'],
            'month 13' => ['2024-13-01'],
            'month 99' => ['2024-99-01'],
            'day 32' => ['2024-01-32'],
            'day 99' => ['2024-01-99'],
            // php ALONE rolled these BACKWARDS into the previous year.
            'month 0' => ['2024-00-01'],
            'day 0' => ['2024-01-00'],
            'month and day both 0' => ['2024-00-00'],
            // The check is on the WRITTEN date, so a time component or an offset cannot
            // smuggle one past it.
            'with a time and Z' => ['2024-02-30T12:00:00Z'],
            'with a space separator and no offset' => ['2024-02-30 00:00:00'],
            'with fractional seconds' => ['2024-02-30T00:00:00.500Z'],
            'with a basic offset' => ['2024-02-30T00:00:00+0500'],
            // The decisive one: this resolves to 2024-02-29T19:00Z, whose UTC date IS a
            // real day -- so an implementation that validated the RESOLVED UTC components
            // instead of the written triple would accept it and stay divergent.
            'offset would shift it onto a real UTC day' => ['2024-02-30T00:00:00+05:00'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('unrealDayProvider')]
    public function testUnrealCalendarDayMatchesNothing(string $operand): void
    {
        $this->assertTrue($this->unparseable($operand), "expected {$operand} to match nothing");
    }

    /**
     * Before the fix "2024-02-30" resolved to 2024-03-01, so this Before comparison was
     * TRUE. Each control assertion repeats it against the date the operand used to roll
     * into, proving the comparison itself still works and only the unreal operand changed.
     */
    public function testRolloverIsGoneNotMerelyUnasserted(): void
    {
        $this->assertFalse($this->dateMatches('2024-02-30', 'Before', '2024-03-02'));
        $this->assertTrue($this->dateMatches('2024-03-01', 'Before', '2024-03-02'));

        $this->assertFalse($this->dateMatches('2023-02-29', 'Before', '2023-03-02'));
        $this->assertTrue($this->dateMatches('2023-03-01', 'Before', '2023-03-02'));
    }

    /** php's own shape: a zero month/day rolled BACKWARDS rather than forwards. */
    public function testZeroMonthAndDayNoLongerRollBackwards(): void
    {
        // "2024-00-01" resolved to 2023-12-01 and "2024-01-00" to 2023-12-31, both of
        // which are After 2023-11-01.
        $this->assertFalse($this->dateMatches('2024-00-01', 'After', '2023-11-01'));
        $this->assertFalse($this->dateMatches('2024-01-00', 'After', '2023-11-01'));
        $this->assertTrue($this->dateMatches('2023-12-01', 'After', '2023-11-01'));
    }

    public function testUnrealDayOnTheConditionSideToo(): void
    {
        $this->assertFalse($this->dateMatches('2024-06-01', 'After', '2024-02-30'));
        $this->assertTrue($this->dateMatches('2024-06-01', 'After', '2024-03-01'));
    }

    /**
     * Every month's true last day, in a leap year and a non-leap year, plus both halves of
     * the century rule. This is what stops the check from over-rejecting, and it walks the
     * whole month-length table rather than sampling it.
     *
     * @return array<string, array{string}>
     */
    public static function realDayProvider(): array
    {
        $cases = [];
        foreach ([[2024, 29], [2023, 28]] as [$year, $feb]) {
            $lengths = [31, $feb, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31];
            foreach ($lengths as $i => $day) {
                $date = sprintf('%04d-%02d-%02d', $year, $i + 1, $day);
                $cases["last day of {$year}-" . sprintf('%02d', $i + 1)] = [$date];
            }
        }
        $cases['first day of the year'] = ['2024-01-01'];
        $cases['last day of the year'] = ['2024-12-31'];
        // Divisible by 400 IS a leap year -- the other half of the century rule.
        $cases['2000 is divisible by 400'] = ['2000-02-29'];
        $cases['1600 is divisible by 400'] = ['1600-02-29'];
        $cases['2400 is divisible by 400'] = ['2400-02-29'];
        $cases['a real day carrying a time and an offset'] = ['2024-02-29T12:00:00+05:00'];

        return $cases;
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('realDayProvider')]
    public function testRealCalendarDayStillResolves(string $operand): void
    {
        $this->assertFalse($this->unparseable($operand), "expected {$operand} to resolve");
    }
}
