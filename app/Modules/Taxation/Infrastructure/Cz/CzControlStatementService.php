<?php

declare(strict_types=1);

namespace App\Modules\Taxation\Infrastructure\Cz;

use App\Modules\Invoicing\Application\Contracts\VatControlStatementSourceInterface;
use App\Modules\Invoicing\Domain\Enums\ReverseChargeMode;
use App\Modules\Invoicing\Domain\ValueObjects\VatControlStatementReportData;
use App\Modules\Invoicing\Domain\ValueObjects\VatControlStatementRowData;
use App\Modules\Invoicing\Domain\ValueObjects\VatControlStatementSummaryRowData;
use App\Modules\Shared\Domain\ValueObjects\SupplierProfile;
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
        private readonly VatControlStatementSourceInterface $collector,
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

    public function toXml(VatControlStatementReportData $report, SupplierProfile $supplier): string
    {
        return $this->xmlBuilder->build($report, $supplier);
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
        foreach ($this->collector->collectIssuedDocuments($userId, $months) as $document) {
            if ($document->reverseChargeMode === ReverseChargeMode::Eu) {
                $assumptions[] = 'Faktury s přenesením daňové povinnosti v rámci EU nejsou součástí této sestavy — patří do souhrnného hlášení (EU sales list).';

                continue;
            }

            if ($document->reverseChargeMode === ReverseChargeMode::Domestic) {
                foreach ($document->rows as $row) {
                    if ($row->base === 0.0 && $row->vat === 0.0) {
                        continue;
                    }

                    $rowSections['A1'][] = $row;
                }

                continue;
            }

            $grossCzk = $this->grossInCzk($document->grossTotal, $document->currency, $document->exchangeRate);

            if (abs($grossCzk) >= self::THRESHOLD_CZK) {
                array_push($rowSections['A4'], ...$document->rows);
            } else {
                $summaryBuckets['A5'] = $this->mergeRowsIntoSummary($summaryBuckets['A5'], $document->rows, skipZeroRows: true);
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
        foreach ($this->collector->collectReceivedDocuments($userId, $months) as $document) {
            if ($document->selfAssessed) {
                array_push($rowSections['B1'], ...$document->rows);

                continue;
            }

            $grossCzk = $this->grossInCzk($document->grossTotal, $document->currency, $document->exchangeRate);

            if (abs($grossCzk) >= self::THRESHOLD_CZK) {
                array_push($rowSections['B2'], ...$document->rows);
            } else {
                $summaryBuckets['B3'] = $this->mergeRowsIntoSummary($summaryBuckets['B3'], $document->rows);
            }
        }
    }

    /**
     * @param  list<VatControlStatementSummaryRowData>  $summary
     * @param  list<VatControlStatementRowData>  $rows
     * @param  bool  $skipZeroRows  A.5 drops an all-zero rate bucket, B.3 keeps
     *                              what the supplier actually recorded — the
     *                              asymmetry predates this refactor and is
     *                              preserved rather than tidied.
     * @return list<VatControlStatementSummaryRowData>
     */
    private function mergeRowsIntoSummary(array $summary, array $rows, bool $skipZeroRows = false): array
    {
        foreach ($rows as $row) {
            if ($skipZeroRows && $row->base === 0.0 && $row->vat === 0.0) {
                continue;
            }

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
}
