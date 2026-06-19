<?php

declare(strict_types=1);

namespace Featureflip\Tests\Evaluation;

use Featureflip\Evaluation\ConditionEvaluator;
use Featureflip\Model\Condition;
use PHPUnit\Framework\TestCase;

final class ConditionEvaluatorTest extends TestCase
{
    private ConditionEvaluator $evaluator;

    protected function setUp(): void
    {
        $this->evaluator = new ConditionEvaluator();
    }

    public function testEqualsMatch(): void
    {
        $condition = new Condition('country', 'equals', ['US'], false);
        $this->assertTrue($this->evaluator->evaluate($condition, ['country' => 'US']));
    }

    public function testEqualsCaseInsensitive(): void
    {
        $condition = new Condition('country', 'equals', ['us'], false);
        $this->assertTrue($this->evaluator->evaluate($condition, ['country' => 'US']));
    }

    public function testEqualsNoMatch(): void
    {
        $condition = new Condition('country', 'equals', ['US'], false);
        $this->assertFalse($this->evaluator->evaluate($condition, ['country' => 'UK']));
    }

    public function testNotEquals(): void
    {
        $condition = new Condition('country', 'not_equals', ['US'], false);
        $this->assertTrue($this->evaluator->evaluate($condition, ['country' => 'UK']));
        $this->assertFalse($this->evaluator->evaluate($condition, ['country' => 'US']));
    }

    public function testIn(): void
    {
        $condition = new Condition('country', 'in', ['US', 'UK', 'CA'], false);
        $this->assertTrue($this->evaluator->evaluate($condition, ['country' => 'UK']));
        $this->assertFalse($this->evaluator->evaluate($condition, ['country' => 'DE']));
    }

    public function testNotIn(): void
    {
        $condition = new Condition('country', 'not_in', ['US', 'UK'], false);
        $this->assertTrue($this->evaluator->evaluate($condition, ['country' => 'DE']));
        $this->assertFalse($this->evaluator->evaluate($condition, ['country' => 'US']));
    }

    public function testContains(): void
    {
        $condition = new Condition('email', 'contains', ['@example'], false);
        $this->assertTrue($this->evaluator->evaluate($condition, ['email' => 'user@example.com']));
        $this->assertFalse($this->evaluator->evaluate($condition, ['email' => 'user@other.com']));
    }

    public function testNotContains(): void
    {
        $condition = new Condition('email', 'not_contains', ['@example'], false);
        $this->assertFalse($this->evaluator->evaluate($condition, ['email' => 'user@example.com']));
        $this->assertTrue($this->evaluator->evaluate($condition, ['email' => 'user@other.com']));
    }

    public function testStartsWith(): void
    {
        $condition = new Condition('name', 'starts_with', ['John'], false);
        $this->assertTrue($this->evaluator->evaluate($condition, ['name' => 'John Doe']));
        $this->assertFalse($this->evaluator->evaluate($condition, ['name' => 'Jane Doe']));
    }

    public function testEndsWith(): void
    {
        $condition = new Condition('email', 'ends_with', ['.com'], false);
        $this->assertTrue($this->evaluator->evaluate($condition, ['email' => 'user@example.com']));
        $this->assertFalse($this->evaluator->evaluate($condition, ['email' => 'user@example.org']));
    }

    public function testMatchesRegex(): void
    {
        $condition = new Condition('email', 'matches_regex', ['^[a-z]+@'], false);
        $this->assertTrue($this->evaluator->evaluate($condition, ['email' => 'user@example.com']));
        $this->assertFalse($this->evaluator->evaluate($condition, ['email' => '123@example.com']));
    }

    public function testMatchesRegexIsCaseSensitive(): void
    {
        // Case-sensitive matching mirrors the engine (RegexOptions.None): a
        // mixed-case pattern matches only the exact case. Neither value nor
        // pattern is lowercased.
        $condition = new Condition('country', 'matches_regex', ['^US$'], false);
        $this->assertTrue($this->evaluator->evaluate($condition, ['country' => 'US']));
        $this->assertFalse($this->evaluator->evaluate($condition, ['country' => 'us']));

        // Case-insensitivity is opt-in via the (?i) inline flag in the pattern.
        $ci = new Condition('country', 'matches_regex', ['(?i)^US$'], false);
        $this->assertTrue($this->evaluator->evaluate($ci, ['country' => 'us']));
    }

    public function testGreaterThan(): void
    {
        $condition = new Condition('age', 'greater_than', ['18'], false);
        $this->assertTrue($this->evaluator->evaluate($condition, ['age' => '21']));
        $this->assertFalse($this->evaluator->evaluate($condition, ['age' => '18']));
    }

    public function testGreaterThanOrEqual(): void
    {
        $condition = new Condition('age', 'greater_than_or_equal', ['18'], false);
        $this->assertTrue($this->evaluator->evaluate($condition, ['age' => '18']));
        $this->assertFalse($this->evaluator->evaluate($condition, ['age' => '17']));
    }

    public function testLessThan(): void
    {
        $condition = new Condition('age', 'less_than', ['18'], false);
        $this->assertTrue($this->evaluator->evaluate($condition, ['age' => '15']));
        $this->assertFalse($this->evaluator->evaluate($condition, ['age' => '18']));
    }

    public function testLessThanOrEqual(): void
    {
        $condition = new Condition('age', 'less_than_or_equal', ['18'], false);
        $this->assertTrue($this->evaluator->evaluate($condition, ['age' => '18']));
        $this->assertFalse($this->evaluator->evaluate($condition, ['age' => '19']));
    }

    public function testBefore(): void
    {
        $condition = new Condition('created', 'before', ['2025-01-01T00:00:00Z'], false);
        $this->assertTrue($this->evaluator->evaluate($condition, ['created' => '2024-06-01T00:00:00Z']));
        $this->assertFalse($this->evaluator->evaluate($condition, ['created' => '2025-06-01T00:00:00Z']));
    }

    public function testAfter(): void
    {
        $condition = new Condition('created', 'after', ['2025-01-01T00:00:00Z'], false);
        $this->assertTrue($this->evaluator->evaluate($condition, ['created' => '2025-06-01T00:00:00Z']));
        $this->assertFalse($this->evaluator->evaluate($condition, ['created' => '2024-06-01T00:00:00Z']));
    }

    // Issue #1443: numeric/date operators must match if the value satisfies the
    // comparison against ANY supplied condition value (mirroring the server
    // engine), not just $targets[0].
    public function testGreaterThanMatchesAnyValue(): void
    {
        $condition = new Condition('age', 'greater_than', ['20', '10'], false);
        // any(15 > 20, 15 > 10) -> true; $targets[0]-only would be false
        $this->assertTrue($this->evaluator->evaluate($condition, ['age' => '15']));
        // below every value -> false
        $this->assertFalse($this->evaluator->evaluate($condition, ['age' => '5']));
    }

    public function testBeforeMatchesAnyValue(): void
    {
        $condition = new Condition('created', 'before', ['2020-01-01T00:00:00Z', '2030-01-01T00:00:00Z'], false);
        // any(2025 < 2020, 2025 < 2030) -> true; $targets[0]-only would be false
        $this->assertTrue($this->evaluator->evaluate($condition, ['created' => '2025-06-01T00:00:00Z']));
    }

    public function testAfterMatchesAnyValue(): void
    {
        $condition = new Condition('created', 'after', ['2030-01-01T00:00:00Z', '2020-01-01T00:00:00Z'], false);
        // any(2025 > 2030, 2025 > 2020) -> true; $targets[0]-only would be false
        $this->assertTrue($this->evaluator->evaluate($condition, ['created' => '2025-06-01T00:00:00Z']));
    }

    public function testRelationalEmptyValuesReturnsFalse(): void
    {
        foreach (['greater_than', 'less_than', 'before', 'after'] as $op) {
            $condition = new Condition('age', $op, [], false);
            $this->assertFalse($this->evaluator->evaluate($condition, ['age' => '15']));
        }
    }

    // --- Date before/after parity (#1455) ---
    //
    // The engine parses both operands as real date-times, normalizes to UTC, and
    // has a unix-seconds fallback. The previous SDK implementation did a pure
    // lexical string compare ($value < $t), which disagrees with the engine on
    // timezone offsets, unix timestamps, and non-date strings.

    private function assertDate(bool $expected, string $operator, string $value, array $targets): void
    {
        $condition = new Condition('ts', $operator, $targets, false);
        $this->assertSame($expected, $this->evaluator->evaluate($condition, ['ts' => $value]));
    }

    public function testDateBeforeHonorsTimezoneOffset(): void
    {
        // 12:00+05:00 == 07:00Z, which is before 08:00Z.
        $this->assertDate(true, 'before', '2026-01-01T12:00:00+05:00', ['2026-01-01T08:00:00Z']);
    }

    public function testDateAfterHonorsTimezoneOffset(): void
    {
        // 12:00+05:00 == 07:00Z, which is NOT after 08:00Z. A lexical compare
        // ("2026-01-01T12:00:00+05:00" > "2026-01-01T08:00:00Z") would say true.
        $this->assertDate(false, 'after', '2026-01-01T12:00:00+05:00', ['2026-01-01T08:00:00Z']);
    }

    public function testDateAfterUnixSecondsValue(): void
    {
        // 1700000000 -> 2023-11-14T22:13:20Z, which is after 2020-01-01.
        $this->assertDate(true, 'after', '1700000000', ['2020-01-01T00:00:00Z']);
    }

    public function testDateBeforeUnixSecondsValue(): void
    {
        $this->assertDate(false, 'before', '1700000000', ['2020-01-01T00:00:00Z']);
    }

    public function testDateUnparseableValueNoMatchNotLexical(): void
    {
        // "hello"/"world" are not dates. A lexical compare ("hello" < "world")
        // would be true; the engine rejects unparseable operands -> no match.
        $this->assertDate(false, 'before', 'hello', ['world']);
        $this->assertDate(false, 'after', 'hello', ['world']);
    }

    public function testDateBeforeNoOffsetAssumedUtc(): void
    {
        // A value with no offset is assumed UTC, so 08:00 (UTC) < 09:00Z.
        $this->assertDate(true, 'before', '2026-01-01T08:00:00', ['2026-01-01T09:00:00Z']);
    }

    public function testDateAfterAndBeforeWithZuluOperands(): void
    {
        $this->assertDate(true, 'after', '2026-06-01T00:00:00Z', ['2026-01-01T00:00:00Z']);
        $this->assertDate(false, 'before', '2026-06-01T00:00:00Z', ['2026-01-01T00:00:00Z']);
    }

    public function testDateAfterMatchesAnyConditionValue(): void
    {
        // any(2026-03 > 2030, 2026-03 > 2020) -> true.
        $this->assertDate(true, 'after', '2026-03-01T00:00:00Z', ['2030-01-01T00:00:00Z', '2020-01-01T00:00:00Z']);
    }

    public function testDateBeforeSkipsUnparseableConditionValue(): void
    {
        // "garbage" is skipped; the value is before 2026-01-01T08:00:00Z.
        $this->assertDate(true, 'before', '2026-01-01T07:30:00Z', ['garbage', '2026-01-01T08:00:00Z']);
    }

    public function testDateAfterUnixSecondsConditionValue(): void
    {
        // The condition value is a unix timestamp -> 2023-11-14T22:13:20Z; the
        // value (2023-11-15) is after it.
        $this->assertDate(true, 'after', '2023-11-15T00:00:00Z', ['1700000000']);
    }

    // Issue #1456: numeric operators previously cast operands with (float), which
    // never fails — (float)"abc" = 0.0, (float)"12abc" = 12.0 — so non-/partial-
    // numeric strings were treated as numbers. The engine's double.TryParse rejects
    // them, so a non-numeric operand must contribute no match.
    public function testNumericNonNumericOperandReturnsFalse(): void
    {
        // (float)"abc" = 0.0 >= -5 used to be true; engine returns false.
        $condition = new Condition('level', 'greater_than_or_equal', ['-5'], false);
        $this->assertFalse($this->evaluator->evaluate($condition, ['level' => 'abc']));

        // (float)"12abc" = 12.0 > 10 used to be true; engine returns false.
        $condition = new Condition('version', 'greater_than', ['10'], false);
        $this->assertFalse($this->evaluator->evaluate($condition, ['version' => '12abc']));

        // A non-numeric condition value is skipped but doesn't break a numeric one.
        $condition = new Condition('age', 'greater_than', ['abc', '10'], false);
        $this->assertTrue($this->evaluator->evaluate($condition, ['age' => '15']));

        // Sanity: a genuinely numeric comparison still matches.
        $condition = new Condition('age', 'greater_than', ['10'], false);
        $this->assertTrue($this->evaluator->evaluate($condition, ['age' => '15']));
    }

    // Issue #1458: type-aware numeric coercion for equality/membership operators
    // (equals/not_equals/in/not_in only). When the RAW context attribute is a
    // native int or float (NOT a bool, NOT a string), the condition literals are
    // compared numerically instead of via stringification — so 1.0-vs-"1"
    // rendering differences agree with the engine. The (string) cast is applied
    // unconditionally otherwise, so "1.0" (string) keeps the lexical string path.
    //
    // @param int|float|bool|string $attribute
    private function assertNumericCoercion(bool $expected, string|int|float|bool $attribute, string $operator, array $values, bool $negate = false): void
    {
        $condition = new Condition('attr', $operator, $values, $negate);
        $this->assertSame($expected, $this->evaluator->evaluate($condition, ['attr' => $attribute]));
    }

    public function testNumericEqualsFloatVsStringRendering(): void
    {
        // 1.0 (float) equals ["1.0"] / ["1"] — both render-match numerically.
        $this->assertNumericCoercion(true, 1.0, 'equals', ['1.0']);
        $this->assertNumericCoercion(true, 1.0, 'equals', ['1']);
    }

    public function testNumericEqualsIntVsStringRendering(): void
    {
        // 1 (int) equals ["1.0"] / ["1"] — both match numerically.
        $this->assertNumericCoercion(true, 1, 'equals', ['1.0']);
        $this->assertNumericCoercion(true, 1, 'equals', ['1']);
    }

    public function testNumericEqualsFractional(): void
    {
        $this->assertNumericCoercion(true, 1.5, 'equals', ['1.5']);
        $this->assertNumericCoercion(false, 1.5, 'equals', ['1']);
    }

    public function testNumericIn(): void
    {
        // any(2 == 1, 2 == 2.0) -> match
        $this->assertNumericCoercion(true, 2, 'in', ['1', '2.0']);
        // 3 matches none -> no match
        $this->assertNumericCoercion(false, 3, 'in', ['1', '2']);
    }

    public function testNumericNotEquals(): void
    {
        // not_equals is the inverse of equals over any-of.
        $this->assertNumericCoercion(false, 1.0, 'not_equals', ['1.0']);
        $this->assertNumericCoercion(true, 1.0, 'not_equals', ['2']);
    }

    public function testNumericNotIn(): void
    {
        $this->assertNumericCoercion(true, 3, 'not_in', ['1', '2']);
    }

    public function testNumericEqualsNonNumericCondition(): void
    {
        // Strict parse: "abc" is not numeric -> no match.
        $this->assertNumericCoercion(false, 1, 'equals', ['abc']);
        // "1abc" is not strictly numeric (is_numeric is false) -> no match.
        $this->assertNumericCoercion(false, 1, 'equals', ['1abc']);
    }

    public function testBooleanIsExcludedFromNumericCoercion(): void
    {
        // CRITICAL: a numeric coercion must NOT make bool true match ["1"].
        // is_int(true)/is_float(true) are both false in PHP, so bool falls
        // through to the string path. (string) true === "1" in PHP, so the
        // string path compares "1" vs "1" -> MATCH for equals ["1"].
        $this->assertNumericCoercion(true, true, 'equals', ['1']);

        // ...but it must NOT match ["1.0"] (string "1" vs "1.0"). If the bool
        // were wrongly numeric-coerced, 1.0 == 1.0 would WRONGLY match here.
        $this->assertNumericCoercion(false, true, 'equals', ['1.0']);

        // true equals ["true"]: (string) true === "1" in PHP, so the string
        // path compares "1" vs "true" -> NO match. This differs from other
        // languages because PHP stringifies bool true as "1", not "true". We
        // assert the ACTUAL current string-path behavior and do not change it.
        $this->assertNumericCoercion(false, true, 'equals', ['true']);
    }

    public function testStringAttributeKeepsLexicalPath(): void
    {
        // "1.0" (string) equals ["1"] -> string compare "1.0" vs "1" -> no match.
        // A numeric coercion would WRONGLY match (1.0 == 1.0).
        $this->assertNumericCoercion(false, '1.0', 'equals', ['1']);
        // "01234" (string) equals ["1234"] -> lexical "01234" vs "1234" -> no
        // match. A numeric coercion would WRONGLY match ((float)"01234" == 1234).
        $this->assertNumericCoercion(false, '01234', 'equals', ['1234']);
    }

    public function testNumericEqualsNegate(): void
    {
        // 1 equals ["2"] is false; negate flips to true.
        $this->assertNumericCoercion(true, 1, 'equals', ['2'], negate: true);
    }

    public function testNegate(): void
    {
        $condition = new Condition('country', 'equals', ['US'], true);
        $this->assertFalse($this->evaluator->evaluate($condition, ['country' => 'US']));
        $this->assertTrue($this->evaluator->evaluate($condition, ['country' => 'UK']));
    }

    public function testMissingAttributeReturnsFalse(): void
    {
        $condition = new Condition('country', 'equals', ['US'], false);
        $this->assertFalse($this->evaluator->evaluate($condition, []));
    }

    public function testMissingAttributeWithNegateReturnsTrue(): void
    {
        $condition = new Condition('country', 'equals', ['US'], true);
        $this->assertTrue($this->evaluator->evaluate($condition, []));
    }

    // A present-but-null attribute must be treated identically to a missing one
    // (short-circuit to negate), NOT coerced to "" and run through the operator
    // (#1460). not_equals exposes the divergence: against "" the operator would
    // match (true), but a null/missing attribute must return the negate value.
    public function testPresentButNullTreatedAsMissing(): void
    {
        $condition = new Condition('country', 'not_equals', ['US'], false);
        // Missing key is the reference behavior...
        $this->assertFalse($this->evaluator->evaluate($condition, []));
        // ...and a present-but-null value must match it.
        $this->assertFalse($this->evaluator->evaluate($condition, ['country' => null]));
    }

    public function testPresentButNullWithNegateTreatedAsMissing(): void
    {
        $condition = new Condition('country', 'not_equals', ['US'], true);
        $this->assertTrue($this->evaluator->evaluate($condition, []));
        $this->assertTrue($this->evaluator->evaluate($condition, ['country' => null]));
    }

    // ReDoS robustness (#1460): the engine bounds catastrophic backtracking with
    // a 100ms timeout; PHP has no regex timeout but PCRE bounds backtracking via
    // pcre.backtrack_limit and returns false on the limit (like an invalid
    // pattern). A pathological pattern must therefore fail safe to no-match and
    // return promptly rather than hanging.
    public function testMatchesRegexCatastrophicBacktrackingFailsSafe(): void
    {
        $condition = new Condition('value', 'matches_regex', ['^(a+)+$'], false);
        $value = str_repeat('a', 100) . '!';
        $this->assertFalse($this->evaluator->evaluate($condition, ['value' => $value]));
    }

    public function testMatchesRegexInvalidPatternIsNoMatch(): void
    {
        $condition = new Condition('value', 'matches_regex', ['([unterminated'], false);
        $this->assertFalse($this->evaluator->evaluate($condition, ['value' => 'anything']));
    }

    // --- PascalCase operators (as sent by the API) ---

    public function testPascalCaseEqualsMatch(): void
    {
        $condition = new Condition('country', 'Equals', ['US'], false);
        $this->assertTrue($this->evaluator->evaluate($condition, ['country' => 'US']));
    }

    public function testPascalCaseNotEquals(): void
    {
        $condition = new Condition('country', 'NotEquals', ['US'], false);
        $this->assertTrue($this->evaluator->evaluate($condition, ['country' => 'UK']));
    }

    public function testPascalCaseIn(): void
    {
        $condition = new Condition('country', 'In', ['US', 'UK'], false);
        $this->assertTrue($this->evaluator->evaluate($condition, ['country' => 'UK']));
    }

    public function testPascalCaseNotIn(): void
    {
        $condition = new Condition('country', 'NotIn', ['US'], false);
        $this->assertTrue($this->evaluator->evaluate($condition, ['country' => 'UK']));
    }

    public function testPascalCaseContains(): void
    {
        $condition = new Condition('email', 'Contains', ['@example'], false);
        $this->assertTrue($this->evaluator->evaluate($condition, ['email' => 'user@example.com']));
    }

    public function testPascalCaseNotContains(): void
    {
        $condition = new Condition('email', 'NotContains', ['@example'], false);
        $this->assertTrue($this->evaluator->evaluate($condition, ['email' => 'user@other.com']));
    }

    public function testPascalCaseStartsWith(): void
    {
        $condition = new Condition('name', 'StartsWith', ['John'], false);
        $this->assertTrue($this->evaluator->evaluate($condition, ['name' => 'John Doe']));
    }

    public function testPascalCaseEndsWith(): void
    {
        $condition = new Condition('email', 'EndsWith', ['.com'], false);
        $this->assertTrue($this->evaluator->evaluate($condition, ['email' => 'user@example.com']));
    }

    public function testPascalCaseMatchesRegex(): void
    {
        $condition = new Condition('email', 'MatchesRegex', ['^[a-z]+@'], false);
        $this->assertTrue($this->evaluator->evaluate($condition, ['email' => 'user@example.com']));
    }

    public function testPascalCaseGreaterThan(): void
    {
        $condition = new Condition('age', 'GreaterThan', ['18'], false);
        $this->assertTrue($this->evaluator->evaluate($condition, ['age' => '21']));
    }

    public function testPascalCaseLessThan(): void
    {
        $condition = new Condition('age', 'LessThan', ['18'], false);
        $this->assertTrue($this->evaluator->evaluate($condition, ['age' => '15']));
    }

    public function testPascalCaseGreaterThanOrEqual(): void
    {
        $condition = new Condition('age', 'GreaterThanOrEqual', ['18'], false);
        $this->assertTrue($this->evaluator->evaluate($condition, ['age' => '18']));
        $this->assertFalse($this->evaluator->evaluate($condition, ['age' => '17']));
    }

    public function testPascalCaseLessThanOrEqual(): void
    {
        $condition = new Condition('age', 'LessThanOrEqual', ['18'], false);
        $this->assertTrue($this->evaluator->evaluate($condition, ['age' => '18']));
        $this->assertFalse($this->evaluator->evaluate($condition, ['age' => '19']));
    }

    public function testPascalCaseBefore(): void
    {
        $condition = new Condition('created', 'Before', ['2025-01-01T00:00:00Z'], false);
        $this->assertTrue($this->evaluator->evaluate($condition, ['created' => '2024-06-01T00:00:00Z']));
    }

    public function testPascalCaseAfter(): void
    {
        $condition = new Condition('created', 'After', ['2025-01-01T00:00:00Z'], false);
        $this->assertTrue($this->evaluator->evaluate($condition, ['created' => '2025-06-01T00:00:00Z']));
    }

    // --- Semver operators (#1433, mirroring js-sdk/.NET SemverComparer) ---

    private function assertSemver(bool $expected, string $operator, string $value, array $targets): void
    {
        $condition = new Condition('version', $operator, $targets, false);
        $this->assertSame($expected, $this->evaluator->evaluate($condition, ['version' => $value]));
    }

    public function testSemverEquals(): void
    {
        $this->assertSemver(true, 'semver_equals', '1.2.3', ['1.2.3']);
        // Missing trailing segments compare as 0, so 2.0 == 2.0.0.
        $this->assertSemver(true, 'semver_equals', '2.0', ['2.0.0']);
        // Optional leading v/V is stripped.
        $this->assertSemver(true, 'semver_equals', 'v1.2.3', ['1.2.3']);
        // Build metadata is ignored for precedence.
        $this->assertSemver(true, 'semver_equals', '1.0.0+build.5', ['1.0.0']);
        $this->assertSemver(false, 'semver_equals', '1.2.3', ['1.2.4']);
    }

    public function testSemverGreaterThan(): void
    {
        // Multi-segment regression: as decimals 2.10 < 2.9, but as semver 2.10 > 2.9.
        $this->assertSemver(true, 'semver_greater_than', '2.10', ['2.9']);
        $this->assertSemver(false, 'semver_greater_than', '2.9', ['2.10']);
        // A release ranks above its prerelease.
        $this->assertSemver(true, 'semver_greater_than', '1.0.0', ['1.0.0-alpha']);
        $this->assertSemver(false, 'semver_greater_than', '1.2.3', ['1.2.3']);
    }

    public function testSemverGreaterThanOrEqual(): void
    {
        // Key regression from #1409: the decimal path silently returned false here.
        $this->assertSemver(true, 'semver_greater_than_or_equal', '2.10.1', ['2.0']);
        $this->assertSemver(true, 'semver_greater_than_or_equal', '1.2.3', ['1.2.3']);
        $this->assertSemver(false, 'semver_greater_than_or_equal', '1.2.3', ['2.0.0']);
    }

    public function testSemverLessThan(): void
    {
        $this->assertSemver(true, 'semver_less_than', '2.9', ['2.10']);
        // A prerelease ranks below its release.
        $this->assertSemver(true, 'semver_less_than', '1.0.0-alpha', ['1.0.0']);
        $this->assertSemver(false, 'semver_less_than', '2.10', ['2.9']);
    }

    public function testSemverLessThanOrEqual(): void
    {
        $this->assertSemver(true, 'semver_less_than_or_equal', '1.2.3', ['2.0.0']);
        $this->assertSemver(true, 'semver_less_than_or_equal', '1.2.3', ['1.2.3']);
        $this->assertSemver(false, 'semver_less_than_or_equal', '2.0.0', ['1.2.3']);
    }

    public function testSemverPrereleasePrecedence(): void
    {
        // Numeric identifiers rank below alphanumeric ones (semver §11).
        $this->assertSemver(true, 'semver_less_than', '1.0.0-1', ['1.0.0-alpha']);
        // alpha < beta lexically.
        $this->assertSemver(true, 'semver_less_than', '1.0.0-alpha', ['1.0.0-beta']);
        // When all shared identifiers are equal, the longer prerelease wins.
        $this->assertSemver(true, 'semver_less_than', '1.0.0-alpha', ['1.0.0-alpha.1']);
        // Numeric prerelease identifiers compare numerically, not lexically.
        $this->assertSemver(true, 'semver_less_than', '1.0.0-2', ['1.0.0-10']);
    }

    public function testSemverMixedCasePrereleaseAsciiOrder(): void
    {
        // Semver §11: alphanumeric prerelease identifiers compare in ASCII sort
        // order, which is case-sensitive — A–Z (65–90) sort before a–z (97–122).
        // A case-folding comparer disagrees with the engine/JS/Go evaluators (#1447).

        // 'B'(66) < 'a'(97): "Beta" < "alpha". Case-folding would order "beta" > "alpha".
        $this->assertSemver(true, 'semver_less_than', '1.0.0-Beta', ['1.0.0-alpha']);
        // "1.0.0-RC" and "1.0.0-rc" are distinct identifiers — a case-folding
        // comparer would treat them as equal.
        $this->assertSemver(false, 'semver_equals', '1.0.0-RC', ['1.0.0-rc']);
        // 'R'(82) < 'r'(114): "RC" < "rc".
        $this->assertSemver(true, 'semver_less_than', '1.0.0-RC', ['1.0.0-rc']);
    }

    public function testSemverUnparseable(): void
    {
        // An unparseable value matches nothing.
        $this->assertSemver(false, 'semver_greater_than', 'not-a-version', ['1.0.0']);
        // An unparseable target contributes no match (it is skipped).
        $this->assertSemver(false, 'semver_greater_than', '1.0.0', ['not-a-version']);
        // A non-numeric release segment is not a version.
        $this->assertSemver(false, 'semver_equals', '1.x.0', ['1.0.0']);
        // A trailing "-" with no prerelease identifiers is malformed.
        $this->assertSemver(false, 'semver_equals', '1.0.0-', ['1.0.0']);
    }

    public function testSemverEmptyTargetsReturnsFalse(): void
    {
        foreach ([
            'semver_equals',
            'semver_greater_than',
            'semver_greater_than_or_equal',
            'semver_less_than',
            'semver_less_than_or_equal',
        ] as $op) {
            $this->assertSemver(false, $op, '1.0.0', []);
        }
    }

    public function testSemverMatchesAnyValue(): void
    {
        // any(2.0.0 > 3.0.0, 2.0.0 > 1.0.0) -> true; $targets[0]-only would be false.
        $this->assertSemver(true, 'semver_greater_than', '2.0.0', ['3.0.0', '1.0.0']);
        // Satisfies none of the supplied versions -> false.
        $this->assertSemver(false, 'semver_greater_than', '0.5.0', ['3.0.0', '1.0.0']);
    }

    // --- PascalCase semver operators (as sent by the API) ---

    public function testPascalCaseSemverGreaterThanOrEqual(): void
    {
        // Verifies the PascalCase→snake_case normalization handles multi-word
        // semver operators (e.g. "SemverGreaterThanOrEqual").
        $condition = new Condition('version', 'SemverGreaterThanOrEqual', ['2.0'], false);
        $this->assertTrue($this->evaluator->evaluate($condition, ['version' => '2.10.1']));
        $this->assertFalse($this->evaluator->evaluate($condition, ['version' => '1.9.9']));
    }

    public function testPascalCaseSemverEquals(): void
    {
        $condition = new Condition('version', 'SemverEquals', ['1.0.0'], false);
        $this->assertTrue($this->evaluator->evaluate($condition, ['version' => 'v1.0.0+build']));
    }

    public function testPascalCaseSemverLessThan(): void
    {
        $condition = new Condition('version', 'SemverLessThan', ['2.10'], false);
        $this->assertTrue($this->evaluator->evaluate($condition, ['version' => '2.9']));
    }
}
