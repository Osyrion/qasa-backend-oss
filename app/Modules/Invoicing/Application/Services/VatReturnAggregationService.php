<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Application\Services;

use App\Modules\Invoicing\Application\Contracts\VatReturnAggregatorInterface;
use App\Modules\Invoicing\Domain\Enums\InvoiceType;
use App\Modules\Invoicing\Domain\Enums\ReverseChargeMode;
use App\Modules\Invoicing\Domain\Enums\SupplierVatRegime;
use App\Modules\Invoicing\Domain\Models\SupplierInvoiceVatLine;
use App\Modules\Invoicing\Domain\Services\VatRecapCalculator;

/**
 * Country-agnostic aggregate for the VAT return itself — the equivalent of
 * VatControlStatementService for the return rather than the control
 * statement. Reuses the same document collector and per-invoice VAT recap
 * as the control statement, but sums into period totals instead of
 * per-document rows, since a return declares totals, not documents.
 *
 * Deliberately excludes credit notes (InvoiceType::CreditNote) and any
 * base/tax correction — folding a correction into the same total as a
 * regular supply would misrepresent which tlačivo row it belongs under
 * (§25/§53 corrections have their own rows the country builder doesn't
 * populate yet — see SkVatReturnService's own assumptions()).
 */
final readonly class VatReturnAggregationService implements VatReturnAggregatorInterface
{
    public function __construct(
        private VatControlStatementService $collector,
        private VatRecapCalculator $recapCalculator,
    ) {}

    /**
     * @return array{
     *     outputByRate: array<int|string, array{base: float, vat: float}>,
     *     euAcquisitionBase: float, euAcquisitionVat: float,
     *     importBase: float, importVat: float,
     *     domesticInputBase: float, domesticInputVat: float,
     * }
     */
    public function aggregate(string $userId, int $year, ?int $quarter, ?int $month): array
    {
        $months = $this->collector->monthsInScope($year, $quarter, $month);

        /** @var array<int|string, array{base: float, vat: float}> $outputByRate */
        $outputByRate = [];
        $euAcquisitionBase = 0.0;
        $euAcquisitionVat = 0.0;
        $importBase = 0.0;
        $importVat = 0.0;
        $domesticInputBase = 0.0;
        $domesticInputVat = 0.0;

        foreach ($this->collector->collectIssuedInvoices($userId, $months) as $invoice) {
            if ($invoice->type === InvoiceType::CreditNote || $invoice->reverse_charge_mode === ReverseChargeMode::Eu) {
                // EU reverse-charge output belongs to the EU sales list, not
                // the return's domestic-rate rows.
                continue;
            }

            if ($invoice->reverse_charge_mode === ReverseChargeMode::Domestic) {
                // Base-only (D=0, customer self-assesses) — same "no row
                // mapped yet" reasoning as the control statement's own gap
                // list, see SkVatReturnService::assumptions().
                continue;
            }

            foreach ($this->recapCalculator->recap($invoice) as $row) {
                if ($row->base === 0.0 && $row->vat === 0.0) {
                    continue;
                }

                $key = $this->rateKey($row->rate);
                $outputByRate[$key]['base'] = ($outputByRate[$key]['base'] ?? 0.0) + $row->base;
                $outputByRate[$key]['vat'] = ($outputByRate[$key]['vat'] ?? 0.0) + $row->vat;
            }
        }

        foreach ($this->collector->collectReceivedInvoices($userId, $months) as $invoice) {
            /** @var SupplierInvoiceVatLine $line */
            foreach ($invoice->vatLines as $line) {
                switch ($invoice->vat_regime) {
                    case SupplierVatRegime::EuReverseCharge:
                        $euAcquisitionBase += (float) $line->base;
                        $euAcquisitionVat += (float) $line->vat_amount;
                        break;
                    case SupplierVatRegime::Import:
                        $importBase += (float) $line->base;
                        $importVat += (float) $line->vat_amount;
                        break;
                    case SupplierVatRegime::Domestic:
                        $domesticInputBase += (float) $line->base;
                        $domesticInputVat += (float) $line->vat_amount;
                        break;
                }
            }
        }

        return [
            'outputByRate' => $outputByRate,
            'euAcquisitionBase' => round($euAcquisitionBase, 2),
            'euAcquisitionVat' => round($euAcquisitionVat, 2),
            'importBase' => round($importBase, 2),
            'importVat' => round($importVat, 2),
            'domesticInputBase' => round($domesticInputBase, 2),
            'domesticInputVat' => round($domesticInputVat, 2),
        ];
    }

    private function rateKey(float $rate): string
    {
        return rtrim(rtrim(number_format($rate, 2, '.', ''), '0'), '.');
    }
}
