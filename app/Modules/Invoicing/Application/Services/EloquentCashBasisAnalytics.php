<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Application\Services;

use App\Modules\Invoicing\Application\Contracts\CashBasisAnalytics;
use App\Modules\Invoicing\Domain\Enums\CashDocumentType;
use App\Modules\Invoicing\Domain\Models\CashDocument;
use App\Modules\Invoicing\Domain\Models\Expense;
use App\Modules\Invoicing\Domain\Models\InvoicePayment;
use App\Modules\Invoicing\Domain\Models\SupplierInvoice;
use App\Modules\Invoicing\Domain\ValueObjects\DatedAmount;
use App\Modules\Shared\Enums\Currency;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;

final class EloquentCashBasisAnalytics implements CashBasisAnalytics
{
    public function collectedInYear(string $ownerId, int $year): array
    {
        // The currency comes from the joined invoice, not from the payment —
        // a payment row has no currency of its own.
        return $this->rows(
            InvoicePayment::query()
                ->join('invoices', 'invoices.id', '=', 'invoice_payments.invoice_id')
                ->where('invoices.user_id', $ownerId)
                ->whereYear('invoice_payments.paid_at', $year)
                ->toBase(),
            'invoice_payments.amount',
            'invoices.currency',
            'invoice_payments.paid_at',
        );
    }

    public function supplierInvoicesPaidInYear(string $ownerId, int $year): array
    {
        return $this->rows(
            SupplierInvoice::withoutGlobalScope('user')
                ->where('user_id', $ownerId)
                ->whereNotNull('paid_at')
                ->whereYear('paid_at', $year)
                ->toBase(),
            'total',
            'currency',
            'paid_at',
        );
    }

    public function expensesInYear(string $ownerId, int $year): array
    {
        return $this->rows(
            Expense::withoutGlobalScope('user')
                ->where('user_id', $ownerId)
                ->whereYear('date', $year)
                ->toBase(),
            'amount',
            'currency',
            'date',
        );
    }

    public function standaloneCashInYear(string $ownerId, int $year, CashDocumentType $type): array
    {
        return $this->rows(
            CashDocument::withoutGlobalScope('user')
                ->where('user_id', $ownerId)
                ->where('type', $type->value)
                ->whereNull('invoice_payment_id')
                ->whereNull('expense_id')
                ->whereYear('issued_at', $year)
                ->toBase(),
            'amount',
            'currency',
            'issued_at',
        );
    }

    /**
     * @param  Builder  $query  already narrowed to one account and one year
     * @return list<DatedAmount>
     */
    private function rows(Builder $query, string $amountColumn, string $currencyColumn, string $dateColumn): array
    {
        /** @var list<object{amount: float|int|string, currency: string, date: string}> $rows */
        $rows = $query->get([
            "{$amountColumn} as amount",
            "{$currencyColumn} as currency",
            "{$dateColumn} as date",
        ])->all();

        return array_map(
            static fn (object $row): DatedAmount => new DatedAmount(
                amount: (float) $row->amount,
                currency: Currency::from((string) $row->currency),
                date: Carbon::parse((string) $row->date)->toDateString(),
            ),
            $rows,
        );
    }
}
