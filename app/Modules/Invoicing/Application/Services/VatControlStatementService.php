<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Application\Services;

use App\Modules\Invoicing\Domain\Enums\InvoiceType;
use App\Modules\Invoicing\Domain\Models\Invoice;
use App\Modules\Invoicing\Domain\Models\SupplierInvoice;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Document collection for the VAT control statement (SK: kontrolný výkaz
 * DPH / CZ: kontrolní hlášení) — the country-agnostic half. Classifying
 * collected documents into a country's own section codes and thresholds is
 * Taxation's Sk/CzControlStatementService; this class only queries.
 */
class VatControlStatementService
{
    /**
     * @param  list<string>  $months  "Y-m" months in scope
     * @return Collection<int, Invoice>
     */
    public function collectIssuedInvoices(string $userId, array $months): Collection
    {
        return $this->restrictToMonths(
            Invoice::withoutGlobalScope('user')
                ->where('user_id', $userId)
                ->whereNotIn('status', ['draft', 'cancelled'])
                ->whereNotIn('type', [InvoiceType::Storno->value, InvoiceType::Proforma->value]),
            $months,
        )->get();
    }

    /**
     * @param  list<string>  $months  "Y-m" months in scope
     * @return Collection<int, SupplierInvoice>
     */
    public function collectReceivedInvoices(string $userId, array $months): Collection
    {
        return $this->restrictToMonths(
            SupplierInvoice::withoutGlobalScope('user')
                ->where('user_id', $userId)
                ->whereNotIn('status', ['draft', 'cancelled'])
                ->with('vatLines'),
            $months,
        )->get();
    }

    /**
     * Narrow a query to the DUZP months in scope.
     *
     * Two predicates rather than one. The BETWEEN is what makes this
     * indexable — it is expressed over the bare
     * COALESCE(taxable_supply_at, issued_at), which is exactly what
     * invoices_user_effective_date_idx covers, whereas the to_char form
     * cannot use that index. The IN then does the precise selection, so the
     * result stays identical even if a caller ever passes months that are
     * not contiguous.
     *
     * @template TModel of Invoice|SupplierInvoice
     *
     * @param  Builder<TModel>  $query
     * @param  list<string>  $months
     * @return Builder<TModel>
     */
    private function restrictToMonths(Builder $query, array $months): Builder
    {
        if ($months === []) {
            // What the in_array() filter did before: nothing in scope, so
            // nothing comes back.
            return $query->whereRaw('1 = 0');
        }

        $effectiveDate = 'COALESCE(taxable_supply_at, issued_at)';

        return $query
            ->whereRaw("{$effectiveDate} BETWEEN ? AND ?", [
                Carbon::parse(min($months).'-01')->startOfMonth()->toDateString(),
                Carbon::parse(max($months).'-01')->endOfMonth()->toDateString(),
            ])
            ->whereIn(DB::raw("to_char({$effectiveDate}, 'YYYY-MM')"), $months);
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
