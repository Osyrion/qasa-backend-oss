<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Domain\Services;

use App\Modules\Invoicing\Domain\Models\Invoice;
use App\Modules\Invoicing\Domain\Models\SupplierInvoice;

/**
 * Builds a KROS Omega text-import (.TXT) payload from frozen invoice/supplier
 * -invoice snapshots: one R01 header row per document followed by one R02
 * row per VAT-rate bucket, matching the R00/R01/R02 row-type convention
 * documented in KROS's own support FAQ.
 *
 * ⚠️ See config/omega.php — the exact column layout is an unverified
 * placeholder (KROS's full column spec lives in a spreadsheet this project
 * could not fetch). Validate against the real KROS import before relying on
 * this in production; the row-type architecture and per-rate VAT breakdown
 * are the parts meant to survive that validation unchanged.
 */
final class OmegaExportBuilder
{
    private const HEADER_ROW = 'R01';

    private const ITEM_ROW = 'R02';

    public function __construct(
        private readonly VatRecapCalculator $recapCalculator,
    ) {}

    /**
     * @param  iterable<Invoice>  $invoices
     */
    public function buildIssued(iterable $invoices): string
    {
        $lines = [];

        foreach ($invoices as $invoice) {
            $snapshot = $invoice->client_snapshot ?? [];

            $lines[] = $this->row([
                self::HEADER_ROW,
                (string) config('omega.document_type'),
                $invoice->invoice_number,
                (string) ($invoice->variable_symbol ?? ''),
                $invoice->issued_at->format('Y-m-d'),
                $invoice->due_at->format('Y-m-d'),
                $invoice->taxable_supply_at?->format('Y-m-d') ?? '',
                (string) ($snapshot['ico'] ?? ''),
                (string) ($snapshot['dic'] ?? ''),
                (string) ($snapshot['name'] ?? ''),
                (string) ($snapshot['address'] ?? ''),
                (string) ($snapshot['city'] ?? ''),
                (string) ($snapshot['postal_code'] ?? ''),
                $invoice->currency->value,
                $this->number((float) $invoice->total),
            ]);

            foreach ($this->recapCalculator->recap($invoice) as $vatRow) {
                $lines[] = $this->row([
                    self::ITEM_ROW,
                    OmegaVatRate::codeFor($vatRow->rate),
                    $this->number($vatRow->rate),
                    $this->number($vatRow->base),
                    $this->number($vatRow->vat),
                    $this->number($vatRow->total),
                ]);
            }
        }

        return $this->encode($lines);
    }

    /**
     * @param  iterable<SupplierInvoice>  $supplierInvoices
     */
    public function buildReceived(iterable $supplierInvoices): string
    {
        $lines = [];

        foreach ($supplierInvoices as $supplierInvoice) {
            $snapshot = $supplierInvoice->vendor_snapshot ?? [];

            $lines[] = $this->row([
                self::HEADER_ROW,
                (string) config('omega.document_type'),
                $supplierInvoice->supplier_invoice_number,
                (string) ($supplierInvoice->variable_symbol ?? ''),
                $supplierInvoice->issued_at->format('Y-m-d'),
                $supplierInvoice->due_at?->format('Y-m-d') ?? '',
                $supplierInvoice->taxable_supply_at?->format('Y-m-d') ?? '',
                (string) ($snapshot['ico'] ?? ''),
                (string) ($snapshot['dic'] ?? ''),
                (string) ($snapshot['name'] ?? ''),
                (string) ($snapshot['address'] ?? ''),
                (string) ($snapshot['city'] ?? ''),
                (string) ($snapshot['postal_code'] ?? ''),
                $supplierInvoice->currency->value,
                $this->number((float) $supplierInvoice->total),
            ]);

            foreach ($supplierInvoice->vatLines as $vatLine) {
                $base = (float) $vatLine->base;
                $vat = (float) $vatLine->vat_amount;

                $lines[] = $this->row([
                    self::ITEM_ROW,
                    OmegaVatRate::codeFor((float) $vatLine->vat_rate),
                    $this->number((float) $vatLine->vat_rate),
                    $this->number($base),
                    $this->number($vat),
                    $this->number($base + $vat),
                ]);
            }
        }

        return $this->encode($lines);
    }

    /**
     * @param  list<string>  $fields
     */
    private function row(array $fields): string
    {
        $delimiter = (string) config('omega.field_delimiter', ';');

        return implode($delimiter, $fields);
    }

    private function number(float $value): string
    {
        return number_format($value, 2, '.', '');
    }

    /**
     * @param  list<string>  $lines
     */
    private function encode(array $lines): string
    {
        if ($lines === []) {
            return '';
        }

        $content = implode("\r\n", $lines)."\r\n";

        // iconv (not mbstring — Windows-1250/CP1250 isn't in every mbstring
        // build) so diacritics in party names/addresses convert correctly.
        $encoding = (string) config('omega.encoding', 'windows-1250');
        $converted = @iconv('UTF-8', $encoding, $content);

        return $converted !== false ? $converted : $content;
    }
}
