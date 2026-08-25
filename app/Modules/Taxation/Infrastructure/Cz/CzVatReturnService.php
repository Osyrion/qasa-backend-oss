<?php

declare(strict_types=1);

namespace App\Modules\Taxation\Infrastructure\Cz;

use App\Modules\Invoicing\Application\Contracts\VatReturnAggregatorInterface;
use App\Modules\Invoicing\Domain\ValueObjects\VatReturnReportData;
use App\Modules\Shared\Domain\ValueObjects\SupplierProfile;
use App\Modules\Taxation\Domain\Contracts\VatReturnBuilder;

/**
 * CZ přiznání DPH (DP3 for EPO) — dphdp3_epo2.xsd was supplied by the user
 * (tests/Fixtures/vat-return/), unlike the earlier state of this class
 * (see git history) which threw for lack of any verified schema. See
 * DphDp3XmlBuilder's own docblock for the row-mapping caveats.
 */
final class CzVatReturnService implements VatReturnBuilder
{
    public function __construct(
        private readonly VatReturnAggregatorInterface $aggregationService,
        private readonly DphDp3XmlBuilder $xmlBuilder,
    ) {}

    public function classify(string $userId, int $year, ?int $quarter = null, ?int $month = null): VatReturnReportData
    {
        $agg = $this->aggregationService->aggregate($userId, $year, $quarter, $month);

        return new VatReturnReportData(
            country: 'CZ',
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
                'Dobropisy (opravné faktúry) nie sú v tejto zostave zohľadnené vôbec — pozri DphDp3XmlBuilder::assumptions().',
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
