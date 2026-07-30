<?php

declare(strict_types=1);

namespace App\Modules\Shared\Support;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * Exact decimal arithmetic for money, over brick/math.
 *
 * Floats cannot hold 0.1, 20.5 or a 15 % discount factor exactly, so a chain
 * of float operations drifts and the cent you round to at the end is
 * occasionally the wrong one. Everything that ends up in a `decimal` column
 * goes through here instead.
 *
 * Two rules the call sites follow:
 *
 *  1. Compute the whole chain at full precision, quantise **once**, at the
 *     point the value is stored or presented. Rounding intermediate steps is
 *     what compounds error.
 *  2. Where the domain deliberately rounds mid-way — VAT is computed from the
 *     already-rounded line total, not from the raw product — that is an
 *     explicit `money()` call with a comment, not an accident.
 *
 * Values come back as numeric strings: Eloquent's `decimal:x` cast reads and
 * writes strings, so nothing ever touches a float on the way to the database.
 */
final class Decimal
{
    /** Scale money is stored and presented at. */
    public const SCALE = 2;

    /**
     * Working precision for intermediate steps — wide enough that no realistic
     * chain of invoice math loses a cent before the final quantisation.
     */
    public const INTERNAL_SCALE = 12;

    public static function of(BigDecimal|string|int|float|null $value): BigDecimal
    {
        if ($value === null) {
            return BigDecimal::zero();
        }

        if ($value instanceof BigDecimal) {
            return $value;
        }

        // BigDecimal::of() on a float would inherit the float's own error, so
        // go through a decimal string first.
        return BigDecimal::of(is_float($value) ? self::floatToString($value) : (string) $value);
    }

    /**
     * Quantise to money scale, half-up — the storage and presentation
     * boundary. Always a plain decimal string such as "1049.33", never
     * scientific notation, so it goes straight into a decimal column.
     *
     * @return numeric-string
     */
    public static function money(BigDecimal|string|int|float|null $value): string
    {
        $quantised = (string) self::of($value)->toScale(self::SCALE, RoundingMode::HalfUp);

        // toScale() cannot produce anything else; the assertion is what lets
        // callers assign straight into a decimal-cast model property.
        assert(is_numeric($quantised));

        return $quantised;
    }

    /**
     * $value * $percent / 100, at full precision — quantise the result yourself.
     */
    public static function percentOf(BigDecimal|string|int|float|null $value, BigDecimal|string|int|float|null $percent): BigDecimal
    {
        return self::of($value)
            ->multipliedBy(self::of($percent))
            ->dividedBy(100, self::INTERNAL_SCALE, RoundingMode::HalfUp);
    }

    /**
     * @param  iterable<BigDecimal|string|int|float|null>  $values
     */
    public static function sum(iterable $values): BigDecimal
    {
        $sum = BigDecimal::zero();

        foreach ($values as $value) {
            $sum = $sum->plus(self::of($value));
        }

        return $sum;
    }

    /**
     * Enough digits to round-trip the double exactly, without the scientific
     * notation or locale separators (string) casting can produce.
     */
    private static function floatToString(float $value): string
    {
        return rtrim(rtrim(number_format($value, 15, '.', ''), '0'), '.') ?: '0';
    }
}
