<?php

declare(strict_types=1);

namespace App\Modules\Taxation\Infrastructure\Sk;

use App\Modules\Invoicing\Application\Contracts\VatReturnAggregatorInterface;
use App\Modules\Invoicing\Domain\ValueObjects\VatReturnReportData;
use App\Modules\Shared\Domain\ValueObjects\SupplierProfile;
use App\Modules\Taxation\Domain\Contracts\VatReturnBuilder;

/**
 * SK priznanie DPH — aggregates via the shared VatReturnAggregationService
 * and delegates rendering to DphXmlBuilder (which owns the actual row
 * mapping and its caveats). Copy-shape of SkControlStatementService.
 */
final class SkVatReturnService implements VatReturnBuilder
{
    public function __construct(
        private readonly VatReturnAggregatorInterface $aggregationService,
        private readonly DphXmlBuilder $xmlBuilder,
    ) {}

    public function classify(string $userId, int $year, ?int $quarter = null, ?int $month = null): VatReturnReportData
    {
        $agg = $this->aggregationService->aggregate($userId, $year, $quarter, $month);

        return new VatReturnReportData(
            country: 'SK',
            year: $year,
            month: $month,
            quarter: $quarter,
            outputByRate: $agg['outputByRate'],
            euAcquisitionBase: $agg['euAcquisitionBase'],
            euAcquisitionVat: $agg['euAcquisitionVat'],
            importBase: $agg['importBase'],
            importVat: $agg['importVat'],
            domesticInputBase: $agg['domesticInputBase'],
            domesticInputVat: $agg['domesticInputVat'],
            assumptions: [
                'Táto zostava je návrh priznania DPH — aplikácia negeneruje ani nepodáva daňové priznanie; správnosť podania je zodpovednosťou používateľa/účtovníka.',
                'Dobropisy (opravné faktúry) nie sú v tejto zostave zohľadnené vôbec — pozri DphXmlBuilder::assumptions().',
            ],
        );
    }

    public function toXml(VatReturnReportData $report, SupplierProfile $supplier): string
    {
        return $this->xmlBuilder->build($report, $supplier);
    }

    public function assumptions(): array
    {
        return $this->xmlBuilder->assumptions();
    }
}
