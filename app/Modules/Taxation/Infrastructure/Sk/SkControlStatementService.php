<?php

declare(strict_types=1);

namespace App\Modules\Taxation\Infrastructure\Sk;

use App\Modules\Invoicing\Application\Contracts\VatControlStatementSourceInterface;
use App\Modules\Invoicing\Domain\Enums\ReverseChargeMode;
use App\Modules\Invoicing\Domain\ValueObjects\VatControlStatementReportData;
use App\Modules\Invoicing\Domain\ValueObjects\VatControlStatementRowData;
use App\Modules\Invoicing\Domain\ValueObjects\VatControlStatementSummaryRowData;
use App\Modules\Shared\Domain\ValueObjects\SupplierProfile;
use App\Modules\Taxation\Domain\Contracts\ControlStatementBuilder;

/**
 * SK kontrolný výkaz DPH classification — sections A1 (vydané tuzemské per
 * doklad, vrátane A2 tuzemský RC), B1 (samozdanenie), B2 (prijaté s
 * odpočtom per doklad), C1 (dobropisy vydané). B3 (zjednodušené doklady) a
 * C2 (dobropisy prijaté) sú vždy prázdne — tento systém ich neeviduje.
 * Copy of CzControlStatementService with SK hardcoded — duplication is
 * deliberate, see docs/plans/TAX_RESIDENCY_PHASE_2_TAXATION_MODULE.md.
 */
final class SkControlStatementService implements ControlStatementBuilder
{
    public function __construct(
        private readonly VatControlStatementSourceInterface $collector,
        private readonly KvDphXmlBuilder $xmlBuilder,
    ) {}

    public function classify(string $userId, int $year, ?int $quarter = null, ?int $month = null): VatControlStatementReportData
    {
        $months = $this->collector->monthsInScope($year, $quarter, $month);

        /** @var array<string, list<VatControlStatementRowData>> $rowSections */
        $rowSections = ['A1' => [], 'A2' => [], 'B1' => [], 'B2' => [], 'C1' => [], 'C2' => []];

        /** @var array<string, list<VatControlStatementSummaryRowData>> $summaryBuckets */
        $summaryBuckets = ['B3' => []];

        $assumptions = [
            'Táto zostava je len podklad pre kontrolný výkaz — aplikácia negeneruje ani nepodáva daňové priznanie; správnosť podania je zodpovednosťou používateľa/účtovníka.',
            'B.3 (zjednodušené doklady) a C.2 (dobropisy prijaté) sú vždy prázdne — zjednodušené doklady a dobropisy k prijatým faktúram tento systém zatiaľ neeviduje.',
        ];

        $this->classifyIssuedInvoices($userId, $months, $rowSections, $assumptions);
        $this->classifyReceivedInvoices($userId, $months, $rowSections);

        return new VatControlStatementReportData(
            country: 'SK',
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
     * @param  list<string>  $assumptions
     */
    private function classifyIssuedInvoices(string $userId, array $months, array &$rowSections, array &$assumptions): void
    {
        foreach ($this->collector->collectIssuedDocuments($userId, $months) as $document) {
            if ($document->reverseChargeMode === ReverseChargeMode::Eu) {
                $assumptions[] = 'Faktúry s prenesením daňovej povinnosti v rámci EÚ nie sú súčasťou tejto zostavy — patria do súhrnného výkazu (EU sales list).';

                continue;
            }

            if ($document->reverseChargeMode === ReverseChargeMode::Domestic) {
                foreach ($document->rows as $row) {
                    if ($row->base === 0.0 && $row->vat === 0.0) {
                        continue;
                    }

                    $rowSections['A2'][] = $row;
                }

                continue;
            }

            if ($document->isCreditNote) {
                foreach ($document->rows as $row) {
                    // Rebuilt rather than pushed through: the corrected
                    // document's number is a property of the credit note, and
                    // C.1 is the only section that prints it — the CZ
                    // statement has no such column, so the source leaves it off
                    // the row rather than filling in a field one reader would
                    // then have to ignore.
                    $rowSections['C1'][] = $this->withRelatedDocument($row, $document->relatedDocumentNumber);
                }

                continue;
            }

            array_push($rowSections['A1'], ...$document->rows);
        }
    }

    private function withRelatedDocument(VatControlStatementRowData $row, ?string $relatedDocumentNumber): VatControlStatementRowData
    {
        return new VatControlStatementRowData(
            $row->documentNumber, $row->date, $row->partnerName, $row->partnerTaxId,
            $row->rate, $row->base, $row->vat,
            relatedDocumentNumber: $relatedDocumentNumber,
        );
    }

    /**
     * @param  list<string>  $months
     * @param  array<string, list<VatControlStatementRowData>>  $rowSections
     */
    private function classifyReceivedInvoices(string $userId, array $months, array &$rowSections): void
    {
        foreach ($this->collector->collectReceivedDocuments($userId, $months) as $document) {
            $section = $document->selfAssessed ? 'B1' : 'B2';

            array_push($rowSections[$section], ...$document->rows);
        }
    }
}
