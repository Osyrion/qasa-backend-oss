<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Application\Services;

use App\Modules\Invoicing\Application\Contracts\TurnoverAnalytics;
use App\Modules\Invoicing\Domain\Enums\InvoiceStatus;
use App\Modules\Invoicing\Domain\Enums\InvoiceType;
use App\Modules\Invoicing\Domain\Models\Expense;
use App\Modules\Invoicing\Domain\Models\Invoice;
use App\Modules\Invoicing\Domain\Models\InvoicePayment;
use App\Modules\Invoicing\Domain\ValueObjects\ClientTurnover;
use App\Modules\Invoicing\Domain\ValueObjects\CurrencyTotal;
use App\Modules\Invoicing\Domain\ValueObjects\MonthlyTurnover;
use App\Modules\Shared\Enums\Currency;
use Illuminate\Database\Query\Builder;

final class EloquentTurnoverAnalytics implements TurnoverAnalytics
{
    /**
     * The document types that count as turnover. A proforma is a request for
     * payment, not a sale, and never appears here.
     *
     * @var list<string>
     */
    private const DOCUMENT_TYPES = [
        InvoiceType::Invoice->value,
        InvoiceType::CreditNote->value,
        InvoiceType::Storno->value,
    ];

    public function invoicedByMonth(string $ownerId, string $from, string $to, ?string $clientId = null): array
    {
        $query = $this->issuedDocuments($ownerId, $from, $to);

        if ($clientId !== null) {
            $query->where('client_id', $clientId);
        }

        return $this->monthly($query, 'currency', 'issued_at', 'total');
    }

    public function collectedByMonth(string $ownerId, string $from, string $to, ?string $clientId = null): array
    {
        $query = $this->payments($ownerId, $from, $to);

        if ($clientId !== null) {
            $query->where('invoices.client_id', $clientId);
        }

        return $this->monthly($query, 'invoices.currency', 'invoice_payments.paid_at', 'invoice_payments.amount');
    }

    public function expensesByMonth(string $ownerId, string $from, string $to): array
    {
        $query = Expense::withoutGlobalScope('user')
            ->where('user_id', $ownerId)
            ->whereBetween('date', [$from, $to])
            ->toBase();

        return $this->monthly($query, 'currency', 'date', 'amount');
    }

    public function invoicedByClient(string $ownerId, string $from, string $to): array
    {
        $rows = $this->issuedDocuments($ownerId, $from, $to)
            ->selectRaw('client_id, currency as currency_code, round(sum(total), 2) as amount')
            ->groupBy('client_id', 'currency_code')
            ->get();

        /** @var list<ClientTurnover> */
        return $rows
            ->map(fn (object $row): ClientTurnover => new ClientTurnover(
                clientId: (string) $row->client_id,
                currency: Currency::from((string) $row->currency_code),
                amount: (float) $row->amount,
            ))
            ->values()
            ->all();
    }

    public function collectedBetween(string $ownerId, string $from, string $to): array
    {
        $rows = $this->payments($ownerId, $from, $to)
            ->selectRaw('invoices.currency as currency_code, round(sum(invoice_payments.amount), 2) as amount')
            ->groupBy('currency_code')
            ->get();

        /** @var list<CurrencyTotal> */
        return $rows
            ->map(fn (object $row): CurrencyTotal => new CurrencyTotal(
                currency: Currency::from((string) $row->currency_code),
                amount: (float) $row->amount,
            ))
            ->values()
            ->all();
    }

    /**
     * Issued (non-draft) sales documents in a date range, as a base query —
     * the select is always an aggregate, so hydrating Invoice out of it would
     * only misdescribe what comes back.
     */
    private function issuedDocuments(string $ownerId, string $from, string $to): Builder
    {
        return Invoice::withoutGlobalScope('user')
            ->where('user_id', $ownerId)
            ->whereNot('status', InvoiceStatus::Draft->value)
            ->whereIn('type', self::DOCUMENT_TYPES)
            ->whereBetween('issued_at', [$from, $to])
            ->toBase();
    }

    /**
     * Payments in a date range, joined to their invoice — the currency of a
     * payment is the invoice's, never the payment row's own.
     */
    private function payments(string $ownerId, string $from, string $to): Builder
    {
        return InvoicePayment::query()
            ->join('invoices', 'invoices.id', '=', 'invoice_payments.invoice_id')
            ->where('invoices.user_id', $ownerId)
            ->whereBetween('invoice_payments.paid_at', [$from, $to])
            ->toBase();
    }

    /**
     * Sum an amount column into one row per currency and calendar month.
     *
     * Rounding sits outside the sum: SUM over numeric is exact decimal
     * arithmetic, so rounding each addend would introduce the drift it looks
     * like it prevents.
     *
     * @param  literal-string  $currencyColumn
     * @param  literal-string  $dateColumn
     * @param  literal-string  $amountColumn
     * @return list<MonthlyTurnover>
     */
    private function monthly(Builder $query, string $currencyColumn, string $dateColumn, string $amountColumn): array
    {
        $rows = $query
            ->selectRaw("{$currencyColumn} as currency_code,
                to_char(date_trunc('month', {$dateColumn}), 'YYYY-MM') as month,
                round(sum({$amountColumn}), 2) as amount")
            ->groupBy($currencyColumn, 'month')
            ->get();

        /** @var list<MonthlyTurnover> */
        return $rows
            ->map(fn (object $row): MonthlyTurnover => new MonthlyTurnover(
                currency: Currency::from((string) $row->currency_code),
                month: (string) $row->month,
                amount: (float) $row->amount,
            ))
            ->values()
            ->all();
    }
}
