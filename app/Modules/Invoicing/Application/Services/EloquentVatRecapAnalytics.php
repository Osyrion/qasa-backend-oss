<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Application\Services;

use App\Modules\Invoicing\Application\Contracts\VatRecapAnalytics;
use App\Modules\Invoicing\Domain\Enums\ExportPeriodBasis;
use App\Modules\Invoicing\Domain\Enums\InvoiceStatus;
use App\Modules\Invoicing\Domain\Enums\InvoiceType;
use App\Modules\Invoicing\Domain\Models\Invoice;
use App\Modules\Invoicing\Domain\Services\VatRecapCalculator;
use App\Modules\Invoicing\Domain\ValueObjects\CurrencyVatRecap;
use App\Modules\Invoicing\Domain\ValueObjects\VatRecapRow;
use App\Modules\Shared\Enums\Currency;

final readonly class EloquentVatRecapAnalytics implements VatRecapAnalytics
{
    /** @var list<string> */
    private const DOCUMENT_TYPES = [
        InvoiceType::Invoice->value,
        InvoiceType::CreditNote->value,
        InvoiceType::Storno->value,
    ];

    public function __construct(
        private VatRecapCalculator $calculator,
    ) {}

    public function recapByRate(
        string $ownerId,
        ExportPeriodBasis $basis,
        string $from,
        string $to,
        ?string $clientId = null,
    ): array {
        $query = Invoice::withoutGlobalScope('user')
            ->where('user_id', $ownerId)
            ->whereNot('status', InvoiceStatus::Draft->value)
            ->whereIn('type', self::DOCUMENT_TYPES)
            ->whereBetween($basis->column(), [$from, $to])
            ->with('items');

        if ($basis === ExportPeriodBasis::Tax) {
            $query->whereNotNull('taxable_supply_at');
        }

        if ($clientId !== null) {
            $query->where('client_id', $clientId);
        }

        /** @var array<string, array<string, array{base: float, vat: float, total: float}>> $buckets currency => rate => sums */
        $buckets = [];

        // The documents are loaded rather than summed in SQL because the
        // per-rate buckets are VatRecapCalculator's, discount apportionment
        // and two-step rounding included. That loop belongs here, inside the
        // module that owns both the schema and the maths — what leaves is the
        // sum.
        foreach ($query->get() as $invoice) {
            $currency = $invoice->currency->value;

            foreach ($this->calculator->recap($invoice) as $row) {
                $rateKey = number_format($row->rate, 2, '.', '');
                $bucket = $buckets[$currency][$rateKey] ?? ['base' => 0.0, 'vat' => 0.0, 'total' => 0.0];

                $buckets[$currency][$rateKey] = [
                    'base' => round($bucket['base'] + $row->base, 2),
                    'vat' => round($bucket['vat'] + $row->vat, 2),
                    'total' => round($bucket['total'] + $row->total, 2),
                ];
            }
        }

        $recaps = [];

        foreach ($buckets as $currency => $rates) {
            ksort($rates, SORT_NUMERIC);

            foreach ($rates as $rate => $sums) {
                $recaps[] = new CurrencyVatRecap(
                    currency: Currency::from($currency),
                    recap: new VatRecapRow((float) $rate, $sums['base'], $sums['vat'], $sums['total']),
                );
            }
        }

        return $recaps;
    }
}
