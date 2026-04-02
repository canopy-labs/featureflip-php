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
}
