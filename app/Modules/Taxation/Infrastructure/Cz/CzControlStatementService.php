<?php

declare(strict_types=1);

namespace App\Modules\Taxation\Infrastructure\Cz;

use App\Modules\Auth\Domain\Models\User;
use App\Modules\Invoicing\Application\DTOs\VatControlStatementReportData;
use App\Modules\Invoicing\Application\DTOs\VatControlStatementRowData;
use App\Modules\Invoicing\Application\DTOs\VatControlStatementSummaryRowData;
use App\Modules\Invoicing\Application\Services\VatControlStatementService;
use App\Modules\Invoicing\Domain\Enums\ReverseChargeMode;
use App\Modules\Invoicing\Domain\Models\SupplierInvoiceVatLine;
use App\Modules\Invoicing\Domain\Services\VatRecapCalculator;
use App\Modules\Invoicing\Domain\Services\VatRecapRow;
use App\Modules\Shared\Enums\Currency;
use App\Modules\Shared\Support\Decimal;
use App\Modules\Taxation\Domain\Contracts\ControlStatementBuilder;

/**
 * CZ kontrolní hlášení classification — sections A1 (tuzemský RC vydané),
 * A4/A5 (vydané, nad/pod 10 000 Kč per doklad), B1 (samozdanenie),
 * B2/B3 (prijaté, nad/pod 10 000 Kč per doklad). Copy of
 * SkControlStatementService with CZ hardcoded — duplication is deliberate,
 * see docs/plans/TAX_RESIDENCY_PHASE_2_TAXATION_MODULE.md.
 */
final class CzControlStatementService implements ControlStatementBuilder
{
    private const float THRESHOLD_CZK = 10000.0;

    public function __construct(
        private readonly VatControlStatementService $collector,
        private readonly VatRecapCalculator $recapCalculator,
        private readonly DphKh1XmlBuilder $xmlBuilder,
    ) {}

    public function classify(string $userId, int $year, ?int $quarter = null, ?int $month = null): VatControlStatementReportData
    {
        $months = $this->collector->monthsInScope($year, $quarter, $month);

        /** @var array<string, list<VatControlStatementRowData>> $rowSections */
        $rowSections = ['A1' => [], 'A4' => [], 'B1' => [], 'B2' => []];

        /** @var array<string, list<VatControlStatementSummaryRowData>> $summaryBuckets */
        $summaryBuckets = ['A5' => [], 'B3' => []];

        $assumptions = [
            'Táto zostava je len podklad pre kontrolní hlášení — aplikácia negeneruje ani nepodáva daňové priznanie; správnosť podania je zodpovednosťou používateľa/účtovníka.',
        ];

        $this->classifyIssuedInvoices($userId, $months, $rowSections, $summaryBuckets, $assumptions);
        $this->classifyReceivedInvoices($userId, $months, $rowSections, $summaryBuckets);

        return new VatControlStatementReportData(
            country: 'CZ',
            year: $year,
            month: $month,
            quarter: $quarter,
            rowSections: $rowSections,
            summarySections: $summaryBuckets,
            assumptions: array_values(array_unique($assumptions)),
        );
    }

    public function toXml(VatControlStatementReportData $report, User $user): string
    {
        return $this->xmlBuilder->build($report, $user);
    }

    public function assumptions(): array
    {
        return $this->xmlBuilder->assumptions();
    }

    /**
     * @param  list<string>  $months
     * @param  array<string, list<VatControlStatementRowData>>  $rowSections
     * @param  array<string, list<VatControlStatementSummaryRowData>>  $summaryBuckets
     * @param  list<string>  $assumptions
     */
    private function classifyIssuedInvoices(string $userId, array $months, array &$rowSections, array &$summaryBuckets, array &$assumptions): void
    {
        $invoices = $this->collector->collectIssuedInvoices($userId, $months);

        foreach ($invoices as $invoice) {
            if ($invoice->reverse_charge_mode === ReverseChargeMode::Eu) {
                $assumptions[] = 'Faktury s přenesením daňové povinnosti v rámci EU nejsou součástí této sestavy — patří do souhrnného hlášení (EU sales list).';

                continue;
            }

            $date = $invoice->taxable_supply_at ?? $invoice->issued_at;
            $documentNumber = (string) $invoice->invoice_number;
            $dateStr = $date->format('Y-m-d');
            $partnerName = (string) ($invoice->client_snapshot['name'] ?? '');
            $partnerTaxId = $this->partnerTaxId($invoice->client_snapshot ?? []);
            $recap = $this->recapCalculator->recap($invoice);

            if ($invoice->reverse_charge_mode === ReverseChargeMode::Domestic) {
                foreach ($recap as $row) {
                    if ($row->base === 0.0 && $row->vat === 0.0) {
                        continue;
                    }

                    $rowSections['A1'][] = new VatControlStatementRowData(
                        $documentNumber, $dateStr, $partnerName, $partnerTaxId, $row->rate, $row->base, $row->vat,
                    );
                }

                continue;
            }

            $grossCzk = $this->grossInCzk((float) $invoice->total, $invoice->currency, $invoice->exchange_rate_snapshot !== null ? (float) $invoice->exchange_rate_snapshot : null);

            if (abs($grossCzk) >= self::THRESHOLD_CZK) {
                foreach ($recap as $row) {
                    $rowSections['A4'][] = new VatControlStatementRowData(
                        $documentNumber, $dateStr, $partnerName, $partnerTaxId, $row->rate, $row->base, $row->vat,
                    );
                }
            } else {
                $summaryBuckets['A5'] = $this->mergeRecapIntoSummary($summaryBuckets['A5'], $recap);
            }
        }
    }

    /**
     * @param  list<string>  $months
     * @param  array<string, list<VatControlStatementRowData>>  $rowSections
     * @param  array<string, list<VatControlStatementSummaryRowData>>  $summaryBuckets
     */
    private function classifyReceivedInvoices(string $userId, array $months, array &$rowSections, array &$summaryBuckets): void
    {
        $invoices = $this->collector->collectReceivedInvoices($userId, $months);

        foreach ($invoices as $invoice) {
            $date = $invoice->taxable_supply_at ?? $invoice->issued_at;
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

            $grossCzk = $this->grossInCzk((float) $invoice->total, $invoice->currency, $invoice->exchange_rate !== null ? (float) $invoice->exchange_rate : null);

            if (abs($grossCzk) >= self::THRESHOLD_CZK) {
                array_push($rowSections['B2'], ...$rows);
            } else {
                $summaryBuckets['B3'] = $this->mergeRowsIntoSummary($summaryBuckets['B3'], $rows);
            }
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
                    (float) Decimal::money(Decimal::of($existing->base)->plus(Decimal::of($base))),
                    (float) Decimal::money(Decimal::of($existing->vat)->plus(Decimal::of($vat))),
                );

                return $summary;
            }
        }

        $summary[] = new VatControlStatementSummaryRowData($rate, (float) Decimal::money($base), (float) Decimal::money($vat));

        return $summary;
    }

    private function grossInCzk(float $total, Currency $currency, ?float $rateToCzk): float
    {
        if ($currency === Currency::CZK) {
            return $total;
        }

        return $rateToCzk !== null
            ? (float) Decimal::money(Decimal::of($total)->multipliedBy(Decimal::of($rateToCzk)))
            : $total;
    }

    /**
     * @param  array<string, mixed>  $snapshot
     */
    private function partnerTaxId(array $snapshot): ?string
    {
        if (($snapshot['is_vat_payer'] ?? false) && ! empty($snapshot['vat_id'])) {
            return (string) $snapshot['vat_id'];
        }

        return ! empty($snapshot['dic']) ? (string) $snapshot['dic'] : null;
    }
}
