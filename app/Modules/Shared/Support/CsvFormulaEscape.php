<?php

declare(strict_types=1);

namespace App\Modules\Shared\Support;

use League\Csv\EscapeFormula;

/**
 * Formula-injection guard for every CSV this application writes, with one
 * exception carved out: a plain number is left alone.
 *
 * League's EscapeFormula prefixes anything starting with `=`, `+`, `@`, `-`,
 * a tab or a carriage return, which is exactly right for text a tenant typed
 * — a client name of `=cmd|'/c calc'!A1` must not execute when the owner (or
 * an operator, for the admin ledger that spans every account) opens the file.
 * But `-` also begins every negative amount, and `'-1200.00` is *text* in a
 * spreadsheet, not a number.
 *
 * That was not a cosmetic problem. A credit note exports with negative
 * subtotal, VAT, total and balance; opened in Excel or LibreOffice, all four
 * cells sat outside any SUM() over the column, so the total an accountant
 * read off the export was too high by exactly the credit notes in it, with
 * nothing on screen to say so.
 *
 * A value matching /^-?\d+([.,]\d+)?$/ cannot express a formula: no `=`, no
 * letters, no parentheses, no `|` or `!`, one optional leading minus. Leaving
 * those unescaped gives back working numeric columns without widening the
 * hole by a character.
 *
 * Both separators are accepted because the exports write comma decimals to
 * match their `;` delimiter — the pair Excel expects in the CZ/SK locale
 * these files are opened in. The comma is never ambiguous with the delimiter
 * inside a field; league/csv quotes the field if it ever needed to be.
 */
final class CsvFormulaEscape
{
    /**
     * Pass to Writer::addFormatter().
     *
     * @return callable(array<array-key, mixed>): array<array-key, mixed>
     */
    public static function formatter(): callable
    {
        $escape = new EscapeFormula;

        return static function (array $record) use ($escape): array {
            $escaped = $escape->escapeRecord($record);

            foreach ($record as $key => $value) {
                if (self::isPlainNumber($value)) {
                    $escaped[$key] = $value;
                }
            }

            return $escaped;
        };
    }

    private static function isPlainNumber(mixed $value): bool
    {
        return (is_string($value) || is_int($value) || is_float($value))
            && preg_match('/^-?\d+([.,]\d+)?$/', (string) $value) === 1;
    }
}
