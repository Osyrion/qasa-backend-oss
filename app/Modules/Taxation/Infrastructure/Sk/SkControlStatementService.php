<?php

declare(strict_types=1);

namespace App\Modules\Taxation\Infrastructure\Sk;

use App\Modules\Auth\Domain\Models\User;
use App\Modules\Invoicing\Application\DTOs\VatControlStatementReportData;
use App\Modules\Invoicing\Application\DTOs\VatControlStatementRowData;
use App\Modules\Invoicing\Application\DTOs\VatControlStatementSummaryRowData;
use App\Modules\Invoicing\Application\Services\VatControlStatementService;
use App\Modules\Invoicing\Domain\Enums\InvoiceType;
use App\Modules\Invoicing\Domain\Enums\ReverseChargeMode;
use App\Modules\Invoicing\Domain\Models\SupplierInvoiceVatLine;
use App\Modules\Invoicing\Domain\Services\VatRecapCalculator;
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
        private readonly VatControlStatementService $collector,
        private readonly VatRecapCalculator $recapCalculator,
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
     * @param  list<string>  $assumptions
     */
    private function classifyIssuedInvoices(string $userId, array $months, array &$rowSections, array &$assumptions): void
    {
        $invoices = $this->collector->collectIssuedInvoices($userId, $months);

        foreach ($invoices as $invoice) {
            if ($invoice->reverse_charge_mode === ReverseChargeMode::Eu) {
                $assumptions[] = 'Faktúry s prenesením daňovej povinnosti v rámci EÚ nie sú súčasťou tejto zostavy — patria do súhrnného výkazu (EU sales list).';

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

                    $rowSections['A2'][] = new VatControlStatementRowData(
                        $documentNumber, $dateStr, $partnerName, $partnerTaxId, $row->rate, $row->base, $row->vat,
                    );
                }

                continue;
            }

            if ($invoice->type === InvoiceType::CreditNote) {
                $relatedDocumentNumber = $invoice->relatedInvoice?->invoice_number;

                foreach ($recap as $row) {
                    $rowSections['C1'][] = new VatControlStatementRowData(
                        $documentNumber, $dateStr, $partnerName, $partnerTaxId, $row->rate, $row->base, $row->vat,
                        relatedDocumentNumber: $relatedDocumentNumber,
                    );
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
     */
    private function classifyReceivedInvoices(string $userId, array $months, array &$rowSections): void
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

            array_push($rowSections['B2'], ...$rows);
        }
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
