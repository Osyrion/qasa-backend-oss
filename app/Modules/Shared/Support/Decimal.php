<?php

declare(strict_types=1);

namespace App\Modules\Shared\Support;

/**
 * Exact decimal arithmetic over bcmath, for money math where float drift
 * (0.1 + 0.2 !== 0.3) is unacceptable. Inputs accept the string|int|float mix
 * Eloquent's `decimal:2` casts and plain literals produce; outputs are
 * strings so callers can feed them straight back into a decimal column or
 * cast to float at the presentation boundary.
 */
final class Decimal
{
    /**
     * bcmath's native $scale argument truncates rather than rounds — e.g.
     * bcmul('33.333', '3', 2) silently gives '99.99' instead of the correctly
     * rounded '100.00'. Every operation below computes at extra precision
     * first and rounds half-away-from-zero only at the very end.
     */
    private const INTERNAL_SCALE = 12;

    /**
     * @param  numeric-string|int|float  $a
     * @param  numeric-string|int|float  $b
     * @return numeric-string
     */
    public static function add(string|int|float $a, string|int|float $b, int $scale = 2): string
    {
        return self::round(bcadd(self::normalize($a), self::normalize($b), self::INTERNAL_SCALE), $scale);
    }

    /**
     * @param  numeric-string|int|float  $a
     * @param  numeric-string|int|float  $b
     * @return numeric-string
     */
    public static function sub(string|int|float $a, string|int|float $b, int $scale = 2): string
    {
        return self::round(bcsub(self::normalize($a), self::normalize($b), self::INTERNAL_SCALE), $scale);
    }

    /**
     * @param  numeric-string|int|float  $a
     * @param  numeric-string|int|float  $b
     * @return numeric-string
     */
    public static function mul(string|int|float $a, string|int|float $b, int $scale = 2): string
    {
        return self::round(bcmul(self::normalize($a), self::normalize($b), self::INTERNAL_SCALE), $scale);
    }

    /**
     * @param  numeric-string|int|float  $a
     * @param  numeric-string|int|float  $b
     * @return numeric-string
     */
    public static function div(string|int|float $a, string|int|float $b, int $scale = 2): string
    {
        return self::round(bcdiv(self::normalize($a), self::normalize($b), self::INTERNAL_SCALE), $scale);
    }

    /**
     * Round half away from zero (PHP's default round() mode), which bcmath
     * has no native support for — it only truncates.
     *
     * @param  numeric-string|int|float  $value
     * @return numeric-string
     */
    public static function round(string|int|float $value, int $scale = 2): string
    {
        $value = self::normalize($value);
        $negative = str_starts_with($value, '-');
        $abs = ltrim($value, '-');

        $factor = bcpow('10', (string) $scale);
        $shifted = bcmul($abs, $factor, $scale + 10);
        $roundedShifted = bcadd($shifted, '0.5', 0);
        $result = bcdiv($roundedShifted, $factor, $scale);

        return $negative && bccomp($result, '0', $scale) !== 0 ? bcmul($result, '-1', $scale) : $result;
    }

    /**
     * @param  numeric-string|int|float  $value
     * @return numeric-string
     */
    private static function normalize(string|int|float $value): string
    {
        return is_string($value) ? $value : (string) $value;
    }
}
