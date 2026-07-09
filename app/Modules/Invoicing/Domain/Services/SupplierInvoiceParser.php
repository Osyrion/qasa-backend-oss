<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Domain\Services;

use Illuminate\Support\Carbon;
use Throwable;

/**
 * Pure regex/heuristic extraction of supplier invoice field suggestions
 * from OCR'd SK/CZ document text — no I/O. Matching the extracted IČO to a
 * vendor client is the caller's responsibility (needs the client repository).
 */
final class SupplierInvoiceParser
{
    /**
     * @return array<string, string|float>
     */
    public function parse(string $text): array
    {
        $normalized = preg_replace('/[ \t]+/', ' ', $text) ?? $text;

        $suggestions = [
            'supplier_invoice_number' => $this->extractInvoiceNumber($normalized),
            'ico' => $this->extractIco($normalized),
            'dic' => $this->extractDic($normalized),
            'issued_at' => $this->extractDate($normalized, ['dátum vystavenia', 'datum vystaveni', 'vystavené dňa', 'vystaveno dne']),
            'due_at' => $this->extractDate($normalized, ['dátum splatnosti', 'datum splatnosti']),
            'taxable_supply_at' => $this->extractDate($normalized, ['dátum zdaniteľného plnenia', 'datum zdanitelneho plneni', 'duzp']),
            'total' => $this->extractTotal($normalized),
            'variable_symbol' => $this->extractVariableSymbol($normalized),
            'iban' => $this->extractIban($text),
            'currency' => $this->extractCurrency($normalized),
        ];

        /** @var array<string, string|float> */
        return array_filter($suggestions, fn (string|float|null $value): bool => $value !== null);
    }

    private function extractInvoiceNumber(string $text): ?string
    {
        if (preg_match('/(?:faktúra|faktura)\s*(?:č\.?|c\.?|číslo|cislo)?\s*[:\s]\s*([A-Za-z0-9\/\-]{4,20})/ui', $text, $m) === 1) {
            return $m[1];
        }

        return null;
    }

    private function extractIco(string $text): ?string
    {
        if (preg_match('/I[CČ]O\s*[:\s]\s*(\d{6,8})/ui', $text, $m) === 1) {
            return $m[1];
        }

        return null;
    }

    private function extractDic(string $text): ?string
    {
        if (preg_match('/DI[CČ]\s*[:\s]\s*(\d{8,12})/ui', $text, $m) === 1) {
            return $m[1];
        }

        return null;
    }

    /**
     * @param  list<string>  $labels
     */
    private function extractDate(string $text, array $labels): ?string
    {
        $labelPattern = implode('|', array_map(fn (string $l): string => preg_quote($l, '/'), $labels));

        if (preg_match('/(?:'.$labelPattern.')\s*[:\s]\s*(\d{1,2}[.\-\/]\d{1,2}[.\-\/]\d{2,4})/ui', $text, $m) === 1) {
            return $this->normalizeDate($m[1]);
        }

        return null;
    }

    private function normalizeDate(string $raw): ?string
    {
        $raw = str_replace(['-', '/'], '.', $raw);

        foreach (['d.m.Y', 'd.m.y'] as $format) {
            try {
                $date = Carbon::createFromFormat($format, $raw);
            } catch (Throwable) {
                continue;
            }

            if ($date !== null) {
                return $date->toDateString();
            }
        }

        return null;
    }

    private function extractTotal(string $text): ?float
    {
        $labelPattern = implode('|', array_map(
            fn (string $l): string => preg_quote($l, '/'),
            ['celkom k úhrade', 'celkem k úhradě', 'suma na úhradu', 'k úhradě', 'k úhrade', 'celkom', 'celkem'],
        ));

        if (preg_match('/(?:'.$labelPattern.')\s*[:\s]*([\d\s]+[,.]\d{2})\s*(?:EUR|€|CZK|Kč|\$|USD)?/ui', $text, $m) === 1) {
            return (float) str_replace([' ', ','], ['', '.'], $m[1]);
        }

        return null;
    }

    private function extractVariableSymbol(string $text): ?string
    {
        if (preg_match('/(?:variabiln[ýy]\s*symbol|VS)\s*[:\s]\s*(\d{1,10})/ui', $text, $m) === 1) {
            return $m[1];
        }

        return null;
    }

    private function extractIban(string $text): ?string
    {
        if (preg_match('/\b([A-Z]{2}\d{2}(?:[ ]?[0-9A-Z]{4}){2,7})\b/', $text, $m) === 1) {
            return str_replace(' ', '', $m[1]);
        }

        return null;
    }

    private function extractCurrency(string $text): ?string
    {
        return match (true) {
            (bool) preg_match('/\bEUR\b|€/u', $text) => 'EUR',
            (bool) preg_match('/\bCZK\b|Kč/u', $text) => 'CZK',
            (bool) preg_match('/\bUSD\b|\$/u', $text) => 'USD',
            default => null,
        };
    }
}
