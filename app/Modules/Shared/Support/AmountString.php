<?php

declare(strict_types=1);

namespace App\Modules\Shared\Support;

/**
 * Reads a money amount out of a string written by someone else.
 *
 * Four parsers had grown the same one-liner — strip the spaces, turn every
 * comma into a dot, cast — and it is wrong for two conventions that arrive
 * constantly:
 *
 *   "1.234,56"  Czech/Slovak with a dot for thousands  -> "1.234.56" -> 1.234
 *   "1,234.56"  Revolut, Wise, Stripe, any en-locale   -> "1.234.56" -> 1.234
 *
 * A payment off by three orders of magnitude, six for "1.234.567,89". It
 * mattered most in the competitor import, where a whole invoice history
 * migrates at a thousandth of its value, and in the OCR of supplier invoice
 * totals; on a bank statement the amount at least fails to match any invoice
 * balance, so nothing auto-applies.
 *
 * The rule: whichever of `.` and `,` appears **last** is the decimal
 * separator, and the other is noise. A lone separator is decimal unless
 * exactly three digits follow it — bank and invoice amounts carry two, so
 * "1.234" is one thousand two hundred thirty-four, not one and a bit. That
 * one heuristic is the only guess here, and it is the right way round for
 * money: reading 1234 as 1.234 loses three orders of magnitude, while the
 * reverse would need a three-decimal currency (KWD, BHD, TND) to even come
 * up, and none of them reach these parsers.
 */
final class AmountString
{
    /**
     * @return float|null null when the string holds no number at all, so the
     *                    caller can tell "absent" from a genuine zero
     */
    public static function parse(?string $raw): ?float
    {
        if ($raw === null) {
            return null;
        }

        // Everything that is not a digit, a separator or a sign: spaces of
        // every width (plain, NBSP, narrow NBSP), currency symbols and codes,
        // stray quotes.
        $cleaned = preg_replace('/[^0-9.,+-]/u', '', $raw) ?? '';

        if ($cleaned === '' || preg_match('/\d/', $cleaned) !== 1) {
            return null;
        }

        $sign = str_starts_with($cleaned, '-') ? -1.0 : 1.0;
        $digits = ltrim($cleaned, '+-');

        $lastDot = strrpos($digits, '.');
        $lastComma = strrpos($digits, ',');

        if ($lastDot !== false && $lastComma !== false) {
            // Both present — the later one is the decimal point.
            $decimalAt = max($lastDot, $lastComma);
            $thousands = $decimalAt === $lastDot ? ',' : '.';

            $digits = str_replace($thousands, '', $digits);
            $digits = str_replace($decimalAt === $lastDot ? '.' : ',', '.', $digits);
        } elseif ($lastDot !== false || $lastComma !== false) {
            $separator = $lastDot !== false ? '.' : ',';
            $occurrences = substr_count($digits, $separator);
            $trailing = strlen($digits) - (int) strrpos($digits, $separator) - 1;

            // Repeated, or three digits behind it: grouping, not a decimal.
            $digits = $occurrences > 1 || $trailing === 3
                ? str_replace($separator, '', $digits)
                : str_replace($separator, '.', $digits);
        }

        return $sign * (float) $digits;
    }

    /**
     * Same, for callers that treat an unreadable amount as nothing.
     */
    public static function toFloat(?string $raw, float $default = 0.0): float
    {
        return self::parse($raw) ?? $default;
    }
}
