<?php

declare(strict_types=1);

namespace Featureflip\Evaluation;

/**
 * Canonical spelling of a floating-point rollout bucket value (#4236).
 *
 * Same text as ECMAScript Number::toString and the Evaluation API's
 * BucketValueFormatter.CanonicalNumber, so every SDK hashes one number to one
 * bucket. Bucket path only; condition evaluation never calls it.
 *
 * Finds the shortest round-trip digits with a sprintf('%.{p}e') loop instead of
 * (string) (precision 14, loses digits) or var_export (depends on the
 * serialize_precision ini setting).
 */
final class CanonicalNumber
{
    public static function format(float $x): string
    {
        if (is_nan($x)) {
            return 'NaN';
        }
        if (is_infinite($x)) {
            return $x > 0 ? 'Infinity' : '-Infinity';
        }
        if ($x == 0.0) {
            return '0';
        }
        if ($x < 0) {
            return '-' . self::format(-$x);
        }

        [$digits, $n] = self::shortestDigits($x);
        $k = strlen($digits);

        if ($k <= $n && $n <= 21) {
            return $digits . str_repeat('0', $n - $k);
        }
        if (0 < $n && $n <= 21) {
            return substr($digits, 0, $n) . '.' . substr($digits, $n);
        }
        if (-6 < $n && $n <= 0) {
            return '0.' . str_repeat('0', -$n) . $digits;
        }
        $e = $n - 1;
        $mantissa = $digits[0] . ($k > 1 ? '.' . substr($digits, 1) : '');
        return $mantissa . 'e' . ($e >= 0 ? '+' : '-') . abs($e);
    }

    /**
     * Value = 0.<digits> * 10^n, digits without leading or trailing zeros.
     *
     * @return array{0: string, 1: int}
     */
    private static function shortestDigits(float $x): array
    {
        for ($p = 0; $p <= 16; $p++) {
            $text = sprintf('%.' . $p . 'e', $x);
            if ((float) $text === $x) {
                return self::split($text);
            }
            // The nearest p+1 digit decimal is not always the one that round-trips (the
            // shortest form can sit one digit away, e.g. 2^-44), so also try its neighbour
            // on x's side.
            if (preg_match('/^(\d)(?:\.(\d+))?e([+-]\d+)$/', $text, $m) === 1) {
                $mantissa = (int) ($m[1] . $m[2]);
                $step = ((float) $text) < $x ? 1 : -1;
                $neighbour = ($mantissa + $step) . 'e' . ((int) $m[3] - $p);
                if ((float) $neighbour === $x) {
                    return self::split($neighbour);
                }
            }
        }
        return self::split(sprintf('%.16e', $x));
    }

    /**
     * Splits "d.ddde+x" or "123e-4" into significant digits and the exponent n.
     *
     * @return array{0: string, 1: int}
     */
    private static function split(string $text): array
    {
        [$mantissa, $exp] = explode('e', $text);
        $dot = strpos($mantissa, '.');
        $intPart = $dot === false ? $mantissa : substr($mantissa, 0, $dot);
        $all = str_replace('.', '', $mantissa);
        $n = strlen($intPart) + (int) $exp;

        $trimmed = ltrim($all, '0');
        $n -= strlen($all) - strlen($trimmed);
        $digits = rtrim($trimmed, '0');
        return [$digits === '' ? '0' : $digits, $n];
    }
}
