<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Application\Contracts;

/**
 * Aggregates a period's invoices into the totals a VAT return reports.
 *
 * Taxation's SK/CZ return builders map these totals onto their own form lines;
 * how the totals are computed is Invoicing's business.
 */
interface VatReturnAggregatorInterface
{
    /**
     * @return array{
     *     outputByRate: array<int|string, array{base: float, vat: float}>,
     *     euAcquisitionBase: float, euAcquisitionVat: float,
     *     importBase: float, importVat: float,
     *     domesticInputBase: float, domesticInputVat: float,
     * }
     */
    public function aggregate(string $userId, int $year, ?int $quarter, ?int $month): array;
}
