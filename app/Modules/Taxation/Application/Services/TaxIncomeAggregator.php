<?php

declare(strict_types=1);

namespace App\Modules\Taxation\Application\Services;

use App\Modules\Auth\Domain\Models\User;
use App\Modules\Invoicing\Application\Contracts\ExchangeRateServiceInterface;
use App\Modules\Invoicing\Domain\Models\Expense;
use App\Modules\Invoicing\Domain\Models\InvoicePayment;
use App\Modules\Invoicing\Domain\Models\SupplierInvoice;
use App\Modules\Shared\Enums\Currency;
use App\Modules\Taxation\Application\DTOs\AggregatedAmountRow;
use App\Modules\Taxation\Application\DTOs\SystemIncomeData;
use App\Modules\Taxation\Domain\Enums\ContributionType;
use App\Modules\Taxation\Domain\Enums\TaxResidency;
use App\Modules\Taxation\Domain\Models\ContributionPayment;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Cash-basis aggregation of what the system already knows for (user, year) —
 * business income actually collected and expenses actually paid, converted
 * into the filing currency for the user's tax residency (SK → EUR, CZ →
 * CZK). Feeds the wizard's system-data prefill; the user only supplies what
 * the system can't derive (personal circumstances, other income sources).
 *
 * Cash basis, not accrual: everything is bucketed by payment/paid date, not
 * issue date — see docs/plans/TAX_RETURN_OSVC_PLAN.md decision 2.
 */
final readonly class TaxIncomeAggregator
{
    public function __construct(
        private ExchangeRateServiceInterface $exchangeRates,
    ) {}

    public function aggregate(User $user, int $year, TaxResidency $residency): SystemIncomeData
    {
        $filingCurrency = $residency->returnCurrency();

        $unconverted = [];

        $businessIncome = $this->sumConverted(
            $this->businessIncomeRows($user, $year),
            $filingCurrency,
            $user->id,
            $unconverted,
        );

        $supplierInvoiceExpenses = $this->sumConverted(
            $this->supplierInvoiceRows($user, $year),
            $filingCurrency,
            $user->id,
            $unconverted,
        );

        $otherExpenses = $this->sumConverted(
            $this->expenseRows($user, $year),
            $filingCurrency,
            $user->id,
            $unconverted,
        );

        $socialPaid = $this->sumConverted(
            $this->contributionRows($user, $year, ContributionType::Social),
            $filingCurrency,
            $user->id,
            $unconverted,
        );

        $healthPaid = $this->sumConverted(
            $this->contributionRows($user, $year, ContributionType::Health),
            $filingCurrency,
            $user->id,
            $unconverted,
        );

        $advancesPaid = $this->sumConverted(
            $this->contributionRows($user, $year, ContributionType::IncomeTaxAdvance),
            $filingCurrency,
            $user->id,
            $unconverted,
        );

        return new SystemIncomeData(
            year: $year,
            currency: $filingCurrency,
            businessIncome: $businessIncome,
            supplierInvoiceExpenses: $supplierInvoiceExpenses,
            otherExpenses: $otherExpenses,
            socialContributionsPaid: $socialPaid,
            healthContributionsPaid: $healthPaid,
            incomeTaxAdvancesPaid: $advancesPaid,
            unconvertedAmounts: $unconverted,
        );
    }

    /**
     * @return Collection<int, AggregatedAmountRow>
     */
    private function businessIncomeRows(User $user, int $year): Collection
    {
        return InvoicePayment::query()
            ->join('invoices', 'invoices.id', '=', 'invoice_payments.invoice_id')
            ->where('invoices.user_id', $user->id)
            ->whereYear('invoice_payments.paid_at', $year)
            ->get(['invoice_payments.amount as amount', 'invoices.currency as currency', 'invoice_payments.paid_at as date'])
            ->map(fn ($row): AggregatedAmountRow => new AggregatedAmountRow(
                amount: (float) $row->amount,
                currency: (string) $row->currency,
                date: Carbon::parse((string) $row->date)->toDateString(),
            ));
    }

    /**
     * @return Collection<int, AggregatedAmountRow>
     */
    private function supplierInvoiceRows(User $user, int $year): Collection
    {
        return SupplierInvoice::query()
            ->withoutGlobalScope('user')
            ->where('user_id', $user->id)
            ->whereNotNull('paid_at')
            ->whereYear('paid_at', $year)
            ->get(['total', 'currency', 'paid_at'])
            ->map(fn (SupplierInvoice $invoice): AggregatedAmountRow => new AggregatedAmountRow(
                amount: (float) $invoice->total,
                currency: $invoice->currency->value,
                date: $invoice->paid_at?->toDateString() ?? sprintf('%d-12-31', $year),
            ));
    }

    /**
     * @return Collection<int, AggregatedAmountRow>
     */
    private function expenseRows(User $user, int $year): Collection
    {
        return Expense::query()
            ->where('user_id', $user->id)
            ->whereYear('date', $year)
            ->get(['amount', 'currency', 'date'])
            ->map(fn (Expense $expense): AggregatedAmountRow => new AggregatedAmountRow(
                amount: (float) $expense->amount,
                currency: $expense->currency->value,
                date: $expense->date->toDateString(),
            ));
    }

    /**
     * @return Collection<int, AggregatedAmountRow>
     */
    private function contributionRows(User $user, int $year, ContributionType $type): Collection
    {
        return ContributionPayment::query()
            ->withoutGlobalScope('user')
            ->where('user_id', $user->id)
            ->where('type', $type->value)
            ->whereYear('paid_at', $year)
            ->get(['amount', 'currency', 'paid_at'])
            ->map(fn (ContributionPayment $payment): AggregatedAmountRow => new AggregatedAmountRow(
                amount: (float) $payment->amount,
                currency: $payment->currency->value,
                date: $payment->paid_at->toDateString(),
            ));
    }

    /**
     * @param  Collection<int, AggregatedAmountRow>  $rows
     * @param  list<array{currency: string, amount: float, date: string}>  $unconverted
     */
    private function sumConverted(Collection $rows, Currency $filingCurrency, string $userId, array &$unconverted): float
    {
        $total = 0.0;

        foreach ($rows as $row) {
            $rowCurrency = Currency::from($row->currency);

            if ($rowCurrency === $filingCurrency) {
                $total += $row->amount;

                continue;
            }

            $converted = $this->exchangeRates->convert($row->amount, $rowCurrency, $filingCurrency, $userId, $row->date);

            if ($converted === null) {
                $unconverted[] = ['currency' => $row->currency, 'amount' => $row->amount, 'date' => $row->date];

                continue;
            }

            $total += $converted;
        }

        return round($total, 2);
    }
}
