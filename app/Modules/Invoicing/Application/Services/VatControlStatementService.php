<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Application\Services;

use App\Modules\Invoicing\Application\DTOs\VatControlStatementReportData;
use App\Modules\Invoicing\Application\DTOs\VatControlStatementRowData;
use App\Modules\Invoicing\Application\DTOs\VatControlStatementSummaryRowData;
use App\Modules\Invoicing\Domain\Enums\InvoiceType;
use App\Modules\Invoicing\Domain\Enums\ReverseChargeMode;
use App\Modules\Invoicing\Domain\Models\Invoice;
use App\Modules\Invoicing\Domain\Models\SupplierInvoice;
use App\Modules\Invoicing\Domain\Models\SupplierInvoiceVatLine;
use App\Modules\Invoicing\Domain\Services\VatRecapCalculator;
use App\Modules\Invoicing\Domain\Services\VatRecapRow;
use App\Modules\Shared\Enums\Currency;

/**
 * Read-only basis for the VAT control statement (SK: kontrolný výkaz DPH /
 * CZ: kontrolní hlášení) — this is a data preparation aid, not a filing: the
 * application does not submit or generate a tax return, correctness of the
 * actual filing remains the taxpayer's/accountant's responsibility.
 *
 * Section codes per country (see PLAN_DRUHA_VLNA.md fáza 7 for the mapping
 * this follows):
 *  - CZ: A1 (tuzemský RC vydané), A4 (vydané > 10 000 Kč per doklad),
 *    A5 (zvyšok kumulatívne), B1 (samozdanenie), B2 (prijaté > 10 000 per
 *    doklad), B3 (zvyšok kumulatívne).
 *  - SK: A1 (vydané tuzemské per doklad), A2 (tuzemský RC vydané),
 *    B1 (samozdanenie), B2 (prijaté s odpočtom per doklad),
 *    B3 (zjednodušené doklady kumulatívne — always empty, not modeled),
 *    C1 (dobropisy vydané), C2 (dobropisy prijaté — always empty, supplier
 *    invoices have no credit-note type in this system).
 *
 * Intra-EU reverse-charged documents are excluded — they belong to the EU
 * sales list (EuSalesListService), not the domestic control statement.
 */
class VatControlStatementService
{
    private const CZ_THRESHOLD_CZK = 10000.0;

    public function __construct(
        private readonly VatRecapCalculator $recapCalculator,
    ) {}

    public function forPeriod(string $userId, string $country, int $year, ?int $quarter = null, ?int $month = null): VatControlStatementReportData
    {
        $months = $this->monthsInScope($year, $quarter, $month);

        /** @var array<string, list<VatControlStatementRowData>> $rowSections */
        $rowSections = $country === 'CZ'
            ? ['A1' => [], 'A4' => [], 'B1' => [], 'B2' => []]
            : ['A1' => [], 'A2' => [], 'B1' => [], 'B2' => [], 'C1' => [], 'C2' => []];

        /** @var array<string, list<VatControlStatementSummaryRowData>> $summaryBuckets per-rate rows, per section */
        $summaryBuckets = $country === 'CZ'
            ? ['A5' => [], 'B3' => []]
            : ['B3' => []];

        $assumptions = [
            'Táto zostava je len podklad pre kontrolný výkaz/kontrolní hlášení — aplikácia negeneruje ani nepodáva daňové priznanie; správnosť podania je zodpovednosťou používateľa/účtovníka.',
        ];

        $this->classifyIssuedInvoices($userId, $months, $country, $rowSections, $summaryBuckets, $assumptions);
        $this->classifyReceivedInvoices($userId, $months, $country, $rowSections, $summaryBuckets, $assumptions);

        if ($country === 'SK') {
            $assumptions[] = 'B.3 (zjednodušené doklady) a C.2 (dobropisy prijaté) sú vždy prázdne — zjednodušené doklady a dobropisy k prijatým faktúram tento systém zatiaľ neeviduje.';
        }

        return new VatControlStatementReportData(
            country: $country,
            year: $year,
            month: $month,
            quarter: $quarter,
            rowSections: $rowSections,
            summarySections: $summaryBuckets,
            assumptions: array_values(array_unique($assumptions)),
        );
    }

    /**
     * @param  list<string>  $months
     * @param  array<string, list<VatControlStatementRowData>>  $rowSections
     * @param  array<string, list<VatControlStatementSummaryRowData>>  $summaryBuckets
     * @param  list<string>  $assumptions
     */
    private function classifyIssuedInvoices(
        string $userId,
        array $months,
        string $country,
        array &$rowSections,
        array &$summaryBuckets,
        array &$assumptions,
    ): void {
        $invoices = Invoice::withoutGlobalScope('user')
            ->where('user_id', $userId)
            ->whereNotIn('status', ['draft', 'cancelled'])
            ->whereNotIn('type', [InvoiceType::Storno->value, InvoiceType::Proforma->value])
            ->get();

        foreach ($invoices as $invoice) {
            $date = $invoice->taxable_supply_at ?? $invoice->issued_at;

            if (! in_array($date->format('Y-m'), $months, true)) {
                continue;
            }

            if ($invoice->reverse_charge_mode === ReverseChargeMode::Eu) {
                $assumptions[] = 'Faktúry s prenesením daňovej povinnosti v rámci EÚ nie sú súčasťou tejto zostavy — patria do súhrnného výkazu (EU sales list).';

                continue;
            }

            $documentNumber = $invoice->invoice_number;
            $dateStr = $date->format('Y-m-d');
            $partnerName = (string) ($invoice->client_snapshot['name'] ?? '');
            $partnerTaxId = $this->partnerTaxId($invoice->client_snapshot ?? []);

            $recap = $this->recapCalculator->recap($invoice);

            if ($invoice->reverse_charge_mode === ReverseChargeMode::Domestic) {
                $section = $country === 'CZ' ? 'A1' : 'A2';

                foreach ($recap as $row) {
                    if ($row->base === 0.0 && $row->vat === 0.0) {
                        continue;
                    }

                    $rowSections[$section][] = new VatControlStatementRowData(
                        $documentNumber, $dateStr, $partnerName, $partnerTaxId, $row->rate, $row->base, $row->vat,
                    );
                }

                continue;
            }

            if ($invoice->type === InvoiceType::CreditNote && $country === 'SK') {
                $relatedDocumentNumber = $invoice->relatedInvoice?->invoice_number;

                foreach ($recap as $row) {
                    $rowSections['C1'][] = new VatControlStatementRowData(
                        $documentNumber, $dateStr, $partnerName, $partnerTaxId, $row->rate, $row->base, $row->vat,
                        relatedDocumentNumber: $relatedDocumentNumber,
                    );
                }

                continue;
            }

            // Domestic taxable supply (or a CZ credit note, folded into the
            // same threshold split as regular invoices).
            if ($country === 'CZ') {
                $grossCzk = $this->grossInCzk((float) $invoice->total, $invoice->currency, $invoice->exchange_rate_snapshot !== null ? (float) $invoice->exchange_rate_snapshot : null);

                if (abs($grossCzk) >= self::CZ_THRESHOLD_CZK) {
                    foreach ($recap as $row) {
                        $rowSections['A4'][] = new VatControlStatementRowData(
                            $documentNumber, $dateStr, $partnerName, $partnerTaxId, $row->rate, $row->base, $row->vat,
                        );
                    }
                } else {
                    $summaryBuckets['A5'] = $this->mergeRecapIntoSummary($summaryBuckets['A5'], $recap);
                }

                continue;
            }

            foreach ($recap as $row) {
                $rowSections['A1'][] = new VatControlStatementRowData(
                    $documentNumber, $dateStr, $partnerName, $partnerTaxId, $row->rate, $row->base, $row->vat,
                );
            }
        }
    }

    /**
     * @param  list<string>  $months
     * @param  array<string, list<VatControlStatementRowData>>  $rowSections
     * @param  array<string, list<VatControlStatementSummaryRowData>>  $summaryBuckets
     * @param  list<string>  $assumptions
     */
    private function classifyReceivedInvoices(
        string $userId,
        array $months,
        string $country,
        array &$rowSections,
        array &$summaryBuckets,
        array &$assumptions,
    ): void {
        $invoices = SupplierInvoice::withoutGlobalScope('user')
            ->where('user_id', $userId)
            ->whereNotIn('status', ['draft', 'cancelled'])
            ->with('vatLines')
            ->get();

        foreach ($invoices as $invoice) {
            $date = $invoice->taxable_supply_at ?? $invoice->issued_at;

            if (! in_array($date->format('Y-m'), $months, true)) {
                continue;
            }

            $documentNumber = $invoice->supplier_invoice_number;
            $dateStr = $date->format('Y-m-d');
            $partnerName = (string) ($invoice->vendor_snapshot['name'] ?? '');
            $partnerTaxId = $this->partnerTaxId($invoice->vendor_snapshot ?? []);

            /** @var list<VatControlStatementRowData> $rows */
            $rows = array_values($invoice->vatLines->map(fn (SupplierInvoiceVatLine $line): VatControlStatementRowData => new VatControlStatementRowData(
                $documentNumber, $dateStr, $partnerName, $partnerTaxId,
                (float) $line->vat_rate, (float) $line->base, (float) $line->vat_amount,
            ))->all());

            if ($invoice->vat_regime->isSelfAssessed()) {
                array_push($rowSections['B1'], ...$rows);

                continue;
            }

            if ($country === 'CZ') {
                $grossCzk = $this->grossInCzk((float) $invoice->total, $invoice->currency, $invoice->exchange_rate !== null ? (float) $invoice->exchange_rate : null);

                if (abs($grossCzk) >= self::CZ_THRESHOLD_CZK) {
                    array_push($rowSections['B2'], ...$rows);
                } else {
                    $summaryBuckets['B3'] = $this->mergeRowsIntoSummary($summaryBuckets['B3'], $rows);
                }

                continue;
            }

            array_push($rowSections['B2'], ...$rows);
        }
    }

    /**
     * @param  list<VatControlStatementSummaryRowData>  $summary
     * @param  list<VatRecapRow>  $recap
     * @return list<VatControlStatementSummaryRowData>
     */
    private function mergeRecapIntoSummary(array $summary, array $recap): array
    {
        foreach ($recap as $row) {
            if ($row->base === 0.0 && $row->vat === 0.0) {
                continue;
            }

            $summary = $this->addRateToSummary($summary, $row->rate, $row->base, $row->vat);
        }

        return $summary;
    }

    /**
     * @param  list<VatControlStatementSummaryRowData>  $summary
     * @param  list<VatControlStatementRowData>  $rows
     * @return list<VatControlStatementSummaryRowData>
     */
    private function mergeRowsIntoSummary(array $summary, array $rows): array
    {
        foreach ($rows as $row) {
            $summary = $this->addRateToSummary($summary, $row->rate, $row->base, $row->vat);
        }

        return $summary;
    }

    /**
     * @param  list<VatControlStatementSummaryRowData>  $summary
     * @return list<VatControlStatementSummaryRowData>
     */
    private function addRateToSummary(array $summary, float $rate, float $base, float $vat): array
    {
        foreach ($summary as $i => $existing) {
            if (abs($existing->rate - $rate) < 0.001) {
                $summary[$i] = new VatControlStatementSummaryRowData(
                    $rate,
                    round($existing->base + $base, 2),
                    round($existing->vat + $vat, 2),
                );

                return $summary;
            }
        }

        $summary[] = new VatControlStatementSummaryRowData($rate, round($base, 2), round($vat, 2));

        return $summary;
    }

    private function grossInCzk(float $total, Currency $currency, ?float $rateToCzk): float
    {
        if ($currency === Currency::CZK) {
            return $total;
        }

        return $rateToCzk !== null ? round($total * $rateToCzk, 2) : $total;
    }

    /**
     * Prefer the VAT-prefixed identifier for registered VAT payers, same
     * precedence used by PohodaXmlBuilder/IsdocBuilder — otherwise fall back
     * to the plain tax id.
     *
     * @param  array<string, mixed>  $snapshot
     */
    private function partnerTaxId(array $snapshot): ?string
    {
        if (($snapshot['is_vat_payer'] ?? false) && ! empty($snapshot['vat_id'])) {
            return (string) $snapshot['vat_id'];
        }

        return ! empty($snapshot['dic']) ? (string) $snapshot['dic'] : null;
    }

    /**
     * @return list<string> "Y-m" months in scope
     */
    private function monthsInScope(int $year, ?int $quarter, ?int $month): array
    {
        if ($month !== null) {
            return [sprintf('%04d-%02d', $year, $month)];
        }

        if ($quarter !== null) {
            $start = ($quarter - 1) * 3 + 1;

            return array_map(
                static fn (int $m): string => sprintf('%04d-%02d', $year, $m),
                range($start, $start + 2),
            );
        }

        return array_map(
            static fn (int $m): string => sprintf('%04d-%02d', $year, $m),
            range(1, 12),
        );
    }
}
