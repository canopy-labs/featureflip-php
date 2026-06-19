<?php

declare(strict_types=1);

namespace Featureflip\Evaluation;

use Featureflip\Model\Condition;

final class ConditionEvaluator
{
    /**
     * @param array<string, mixed> $context
     */
    public function evaluate(Condition $condition, array $context): bool
    {
        // A missing key OR a present-but-null value is treated as "attribute
        // absent" → return negate. Never coerce null to "" and run the operator
        // (#1460): the engine filters null attributes in ClientContextMapper and
        // every other SDK short-circuits on null, so PHP must too.
        if (!array_key_exists($condition->attribute, $context) || $context[$condition->attribute] === null) {
            return $condition->negate;
        }

        $raw = $context[$condition->attribute];

        // Type-aware numeric coercion for equality/membership operators (#1458).
        // When the RAW attribute is a native int or float — NOT a bool (is_int /
        // is_float exclude bool in PHP) and NOT a string — compare the condition
        // literals numerically rather than via stringification, so 1.0-vs-"1"
        // rendering differences agree with the engine. Strings keep the lexical
        // path: is_numeric on the already-cast string would wrongly treat "1.0"
        // or "01234" as numeric, so the check is on the RAW value's type only.
        if ((is_int($raw) || is_float($raw)) && $this->isEqualityOperator($condition->operator)) {
            $op = $this->normalizeOperator($condition->operator);
            $anyEqual = $this->anyMatch(
                $condition->values,
                fn(string $v) => ($n = $this->parseStrict($v)) !== null && $n === (float) $raw,
            );
            $result = in_array($op, ['equals', 'in'], true) ? $anyEqual : !$anyEqual;

            return $condition->negate ? !$result : $result;
        }

        $attributeValue = (string) $raw;
        $result = $this->evaluateOperator($condition->operator, $attributeValue, $condition->values);

        return $condition->negate ? !$result : $result;
    }

    /**
     * True when the operator is one of the four equality/membership operators
     * eligible for type-aware numeric coercion (#1458): equals, not_equals, in,
     * not_in. Never contains/starts_with/ends_with. Handles PascalCase input.
     */
    private function isEqualityOperator(string $operator): bool
    {
        return in_array(
            $this->normalizeOperator($operator),
            ['equals', 'not_equals', 'in', 'not_in'],
            true,
        );
    }

    /**
     * Normalizes PascalCase operators from the API (e.g. "NotEquals") to
     * snake_case ("not_equals").
     */
    private function normalizeOperator(string $operator): string
    {
        return strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $operator) ?? $operator);
    }

    /**
     * Strictly parses a condition literal as a number for type-aware numeric
     * coercion (#1458). Returns null when the literal is not numeric — mirroring
     * the engine's double.TryParse, which rejects "abc"/"1abc". is_numeric is
     * deliberate: (float) casting alone silently coerces "1abc" to 1.0 and would
     * produce matches the engine rejects.
     */
    private function parseStrict(string $v): ?float
    {
        return is_numeric($v) ? (float) $v : null;
    }

    /**
     * @param string[] $targets
     */
    private function evaluateOperator(string $operator, string $value, array $targets): bool
    {
        // Normalize PascalCase operators from API (e.g. "NotEquals") to snake_case ("not_equals")
        $operator = $this->normalizeOperator($operator);

        return match ($operator) {
            'equals', 'in' => $this->anyMatch($targets, fn(string $t) => mb_strtolower($value) === mb_strtolower($t)),
            'not_equals', 'not_in' => !$this->anyMatch($targets, fn(string $t) => mb_strtolower($value) === mb_strtolower($t)),
            'contains' => $this->anyMatch($targets, fn(string $t) => str_contains(mb_strtolower($value), mb_strtolower($t))),
            'not_contains' => !$this->anyMatch($targets, fn(string $t) => str_contains(mb_strtolower($value), mb_strtolower($t))),
            'starts_with' => $this->anyMatch($targets, fn(string $t) => str_starts_with(mb_strtolower($value), mb_strtolower($t))),
            'ends_with' => $this->anyMatch($targets, fn(string $t) => str_ends_with(mb_strtolower($value), mb_strtolower($t))),
            // Case-sensitive matching on the original-case value and pattern,
            // mirroring the engine (RegexOptions.None). Case-insensitivity is
            // opt-in via the (?i) inline flag in the pattern. matchesRegex()
            // fails safe to no-match for malformed or pathological patterns.
            'matches_regex' => $this->anyMatch($targets, fn(string $t) => $this->matchesRegex($t, $value)),
            // Relational operators match if the value satisfies the comparison
            // against ANY condition value (mirroring the server engine), not just
            // $targets[0]. A non-numeric value/target contributes no match, and an
            // empty value list yields false.
            'greater_than' => $this->numericMatches($value, $targets, fn(int $c) => $c > 0),
            'greater_than_or_equal' => $this->numericMatches($value, $targets, fn(int $c) => $c >= 0),
            'less_than' => $this->numericMatches($value, $targets, fn(int $c) => $c < 0),
            'less_than_or_equal' => $this->numericMatches($value, $targets, fn(int $c) => $c <= 0),
            // Date operators parse both operands as real date-times, normalize to
            // UTC, and fall back to unix seconds — mirroring the engine's
            // CompareDateTime. An unparseable value matches nothing (never a
            // lexical string compare); unparseable condition values are skipped.
            'before' => $this->dateMatches($value, $targets, fn(int $c) => $c < 0),
            'after' => $this->dateMatches($value, $targets, fn(int $c) => $c > 0),
            // Semantic-version operators compare $value against each target as a
            // semver rather than a decimal, matching if the comparison satisfies
            // the operator for ANY supplied value (mirroring the engine and the
            // numeric/date any-of semantics). An unparseable value or target
            // contributes no match.
            'semver_equals' => $this->semverMatches($value, $targets, fn(int $c) => $c === 0),
            'semver_greater_than' => $this->semverMatches($value, $targets, fn(int $c) => $c > 0),
            'semver_greater_than_or_equal' => $this->semverMatches($value, $targets, fn(int $c) => $c >= 0),
            'semver_less_than' => $this->semverMatches($value, $targets, fn(int $c) => $c < 0),
            'semver_less_than_or_equal' => $this->semverMatches($value, $targets, fn(int $c) => $c <= 0),
            default => false,
        };
    }

    /**
     * Runs a config-supplied regex against $value, failing safe to no-match
     * (#1460). The engine bounds catastrophic backtracking with a 100ms regex
     * timeout; PHP has no per-regex timeout, but PCRE already bounds
     * backtracking via pcre.backtrack_limit (default 1,000,000) and returns
     * false when the limit is exceeded — the same false it returns for an
     * invalid pattern. We treat anything other than an explicit match (1) as
     * no-match, so a pathological or malformed pattern can neither hang the
     * evaluator nor surface a warning. Matching is case-sensitive (no ~i flag),
     * mirroring the engine's RegexOptions.None (#1453); the "~" delimiter is
     * escaped in the pattern body.
     */
    private function matchesRegex(string $pattern, string $value): bool
    {
        return @preg_match('~' . str_replace('~', '\\~', $pattern) . '~', $value) === 1;
    }

    /**
     * @param string[] $targets
     * @param callable(string): bool $predicate
     */
    private function anyMatch(array $targets, callable $predicate): bool
    {
        foreach ($targets as $target) {
            if ($predicate($target)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Returns true when comparing $value (parsed as a number) against ANY numeric
     * target satisfies $predicate. A non-numeric $value matches nothing and
     * non-numeric targets are skipped — mirroring the engine's CompareNumeric
     * (double.TryParse). (float) casting is deliberately avoided: it silently
     * coerces "abc" to 0.0 and "12abc" to 12.0, producing matches the engine
     * rejects (#1456).
     *
     * @param string[] $targets
     * @param callable(int): bool $predicate receives the comparison sign (-1/0/1)
     */
    private function numericMatches(string $value, array $targets, callable $predicate): bool
    {
        if (!is_numeric($value)) {
            return false;
        }
        $left = (float) $value;
        foreach ($targets as $target) {
            if (!is_numeric($target)) {
                continue;
            }
            if ($predicate($left <=> (float) $target)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Returns true when comparing $value (parsed as a UTC date-time) against ANY
     * parseable target satisfies $predicate. An unparseable $value matches
     * nothing — there is NO lexical string-compare fallback (mirroring the
     * engine's CompareDateTime). Unparseable targets are skipped.
     *
     * @param string[] $targets
     * @param callable(int): bool $predicate receives the comparison sign (-1/0/1)
     */
    private function dateMatches(string $value, array $targets, callable $predicate): bool
    {
        $left = $this->parseDateTime($value);
        if ($left === null) {
            return false;
        }
        foreach ($targets as $target) {
            $right = $this->parseDateTime($target);
            if ($right === null) {
                continue;
            }
            // DateTimeImmutable supports the spaceship operator, returning -1/0/1.
            if ($predicate($left <=> $right)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Parses a date-time string into a UTC \DateTimeImmutable, mirroring the
     * evaluation service's TryParseDateTime so server-side and SDK-local
     * evaluation agree:
     *   1. ISO-8601 date-times. When the string carries an offset (e.g. +05:00 or
     *      Z) it is honored; when it has no offset it is assumed UTC. The result
     *      is normalized to UTC.
     *   2. Bare integers fall back to Unix time in SECONDS.
     *   3. Anything else returns null (no match).
     *
     * The ISO regex guard is deliberate: new \DateTimeImmutable() is very lenient
     * (it would accept relative strings like "now"/"tomorrow"), so non-date inputs
     * must be rejected before reaching it to match the engine.
     */
    private function parseDateTime(string $value): ?\DateTimeImmutable
    {
        $s = trim($value);
        if ($s === '') {
            return null;
        }

        // ISO-8601 date or date-time, with an optional time, fractional seconds,
        // and an optional Z / numeric timezone offset.
        $isoPattern = '/^\d{4}-\d{2}-\d{2}([T ]\d{2}:\d{2}(:\d{2})?(\.\d+)?(Z|[+-]\d{2}:?\d{2})?)?$/';
        if (preg_match($isoPattern, $s)) {
            try {
                // A supplied UTC timezone is only used when $s has no offset; an
                // explicit offset/Z in $s takes precedence. Normalize to UTC.
                return (new \DateTimeImmutable($s, new \DateTimeZone('UTC')))
                    ->setTimezone(new \DateTimeZone('UTC'));
            } catch (\Exception) {
                return null;
            }
        }

        // Unix timestamp fallback (seconds since epoch). The "@" format always
        // interprets the value as UTC.
        if (preg_match('/^-?\d+$/', $s)) {
            try {
                return (new \DateTimeImmutable('@' . $s))
                    ->setTimezone(new \DateTimeZone('UTC'));
            } catch (\Exception) {
                return null;
            }
        }

        return null;
    }

    /**
     * Returns true when comparing $value (as a semantic version) against ANY
     * parseable target satisfies $predicate. An unparseable $value matches
     * nothing; unparseable targets are skipped.
     *
     * @param string[] $targets
     * @param callable(int): bool $predicate receives the comparison sign (-1/0/1)
     */
    private function semverMatches(string $value, array $targets, callable $predicate): bool
    {
        $left = $this->parseSemver($value);
        if ($left === null) {
            return false;
        }
        foreach ($targets as $target) {
            $right = $this->parseSemver($target);
            if ($right === null) {
                continue;
            }
            if ($predicate($this->compareSemver($left, $right))) {
                return true;
            }
        }
        return false;
    }

    /**
     * Parses a semantic-version string (https://semver.org), mirroring the
     * evaluation service's SemverComparer so server-side and SDK-local
     * evaluation agree. Tolerates an optional leading v/V, an arbitrary number
     * of dot-separated numeric release segments (missing trailing segments
     * compare as 0, so 2.0 == 2.0.0), an optional -prerelease suffix, and
     * +build metadata (ignored for precedence). Returns null when the release
     * core is missing or any release segment is non-numeric.
     *
     * @return array{release: string[], prerelease: string[]}|null
     */
    private function parseSemver(string $value): ?array
    {
        $s = trim($value);
        if ($s === '') {
            return null;
        }

        // Optional leading "v"/"V".
        if ($s[0] === 'v' || $s[0] === 'V') {
            $s = substr($s, 1);
        }

        // Build metadata ("+...") does not affect precedence.
        $plus = strpos($s, '+');
        if ($plus !== false) {
            $s = substr($s, 0, $plus);
        }

        // Split the release core from the optional "-prerelease" suffix.
        $prerelease = [];
        $dash = strpos($s, '-');
        if ($dash !== false) {
            $core = substr($s, 0, $dash);
            $pre = substr($s, $dash + 1);
            if ($pre === '') {
                return null; // trailing "-" with no identifiers is malformed
            }
            $prerelease = explode('.', $pre);
            foreach ($prerelease as $id) {
                if ($id === '') {
                    return null;
                }
            }
        } else {
            $core = $s;
        }

        if ($core === '') {
            return null;
        }
        $release = explode('.', $core);
        foreach ($release as $seg) {
            if (!$this->isAllDigits($seg)) {
                return null;
            }
        }

        return ['release' => $release, 'prerelease' => $prerelease];
    }

    /**
     * Returns -1, 0, or 1 comparing semver $a to $b.
     *
     * @param array{release: string[], prerelease: string[]} $a
     * @param array{release: string[], prerelease: string[]} $b
     */
    private function compareSemver(array $a, array $b): int
    {
        $max = max(count($a['release']), count($b['release']));
        for ($i = 0; $i < $max; $i++) {
            $segA = $a['release'][$i] ?? '0';
            $segB = $b['release'][$i] ?? '0';
            $cmp = $this->compareNumericString($segA, $segB);
            if ($cmp !== 0) {
                return $cmp;
            }
        }

        return $this->comparePrerelease($a['prerelease'], $b['prerelease']);
    }

    /**
     * @param string[] $a
     * @param string[] $b
     */
    private function comparePrerelease(array $a, array $b): int
    {
        $countA = count($a);
        $countB = count($b);

        // A version with no prerelease has higher precedence than one with a prerelease.
        if ($countA === 0 && $countB === 0) {
            return 0;
        }
        if ($countA === 0) {
            return 1;
        }
        if ($countB === 0) {
            return -1;
        }

        $min = min($countA, $countB);
        for ($i = 0; $i < $min; $i++) {
            $cmp = $this->comparePrereleaseId($a[$i], $b[$i]);
            if ($cmp !== 0) {
                return $cmp;
            }
        }

        // All shared identifiers equal: the longer prerelease has higher precedence.
        return $countA <=> $countB;
    }

    private function comparePrereleaseId(string $a, string $b): int
    {
        $aNum = $this->isAllDigits($a);
        $bNum = $this->isAllDigits($b);

        // Numeric identifiers always have lower precedence than alphanumeric ones.
        if ($aNum && $bNum) {
            return $this->compareNumericString($a, $b);
        }
        if ($aNum) {
            return -1;
        }
        if ($bNum) {
            return 1;
        }

        // Semver §11: alphanumeric identifiers compare in ASCII sort order
        // (case-sensitive). strcmp is byte-wise, so 'A'(65) sorts before 'a'(97)
        // — never fold case here (#1447).
        return strcmp($a, $b) <=> 0;
    }

    /**
     * Compares two all-digit strings as non-negative integers without parsing
     * (overflow-free): strip leading zeros, then the longer string is the larger
     * number; equal lengths compare byte-wise.
     */
    private function compareNumericString(string $a, string $b): int
    {
        $a = ltrim($a, '0');
        $b = ltrim($b, '0');
        $lenA = strlen($a);
        $lenB = strlen($b);
        if ($lenA !== $lenB) {
            return $lenA < $lenB ? -1 : 1;
        }
        return strcmp($a, $b) <=> 0;
    }

    private function isAllDigits(string $s): bool
    {
        return $s !== '' && ctype_digit($s);
    }
}
