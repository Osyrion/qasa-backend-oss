<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Application\Services;

use App\Modules\Invoicing\Domain\Enums\InvoiceType;
use App\Modules\Invoicing\Domain\Enums\ReverseChargeMode;
use App\Modules\Invoicing\Domain\Models\Invoice;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Document collection for the EU sales list (súhrnný výkaz) — issued
 * intra-EU reverse-charged invoices for a period. Grouping/row-shaping is
 * Taxation's Sk/CzEuSalesListBuilder; this class only queries.
 */
class EuSalesListService
{
    /**
     * @param  list<string>  $months  "Y-m" months in scope
     * @return Collection<int, Invoice>
     */
    public function collect(string $userId, array $months): Collection
    {
        if ($months === []) {
            // What the in_array() filter did before: nothing in scope, so
            // nothing comes back.
            /** @var Collection<int, Invoice> */
            return new Collection;
        }

        $effectiveDate = 'COALESCE(taxable_supply_at, issued_at)';

        return Invoice::withoutGlobalScope('user')
            ->where('user_id', $userId)
            ->where('reverse_charge_mode', ReverseChargeMode::Eu->value)
            ->whereNotIn('status', ['draft', 'cancelled'])
            ->whereNotIn('type', [InvoiceType::Storno->value, InvoiceType::Proforma->value])
            // BETWEEN over the bare expression so invoices_user_effective_date_idx
            // applies; the to_char IN then selects the exact months, which also
            // keeps the result right if the months are ever non-contiguous.
            ->whereRaw("{$effectiveDate} BETWEEN ? AND ?", [
                Carbon::parse(min($months).'-01')->startOfMonth()->toDateString(),
                Carbon::parse(max($months).'-01')->endOfMonth()->toDateString(),
            ])
            ->whereIn(DB::raw("to_char({$effectiveDate}, 'YYYY-MM')"), $months)
            ->get();
    }

    /**
     * @return list<string> "Y-m" months in scope
     */
    public function monthsInScope(int $year, ?int $quarter, ?int $month): array
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
