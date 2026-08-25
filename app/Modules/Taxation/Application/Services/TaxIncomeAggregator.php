<?php

declare(strict_types=1);

namespace App\Modules\Taxation\Application\Services;

use App\Modules\Invoicing\Application\Contracts\CashBasisAnalytics;
use App\Modules\Invoicing\Application\Contracts\ExchangeRateServiceInterface;
use App\Modules\Invoicing\Domain\Enums\CashDocumentType;
use App\Modules\Invoicing\Domain\ValueObjects\DatedAmount;
use App\Modules\Shared\Domain\Contracts\Account;
use App\Modules\Shared\Enums\Currency;
use App\Modules\Taxation\Application\Contracts\TaxIncomeAggregatorInterface;
use App\Modules\Taxation\Application\DTOs\AggregatedAmountRow;
use App\Modules\Taxation\Domain\Enums\ContributionType;
use App\Modules\Taxation\Domain\Enums\TaxResidency;
use App\Modules\Taxation\Domain\Models\ContributionPayment;
use App\Modules\Taxation\Domain\ValueObjects\SystemIncomeData;
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
final readonly class TaxIncomeAggregator implements TaxIncomeAggregatorInterface
{
    public function __construct(
        private ExchangeRateServiceInterface $exchangeRates,
        private CashBasisAnalytics $cashBasis,
    ) {}

    public function aggregate(Account $user, int $year, TaxResidency $residency): SystemIncomeData
    {
        $filingCurrency = $residency->returnCurrency();

        $unconverted = [];

        $businessIncome = $this->sumConverted(
            $this->businessIncomeRows($user, $year)->concat($this->standaloneCashRows($user, $year, CashDocumentType::Income)),
            $filingCurrency,
            $user->accountOwnerId(),
            $unconverted,
        );

        $supplierInvoiceExpenses = $this->sumConverted(
            $this->supplierInvoiceRows($user, $year),
            $filingCurrency,
            $user->accountOwnerId(),
            $unconverted,
        );

        $otherExpenses = $this->sumConverted(
            $this->expenseRows($user, $year)->concat($this->standaloneCashRows($user, $year, CashDocumentType::Expense)),
            $filingCurrency,
            $user->accountOwnerId(),
            $unconverted,
        );

        $socialPaid = $this->sumConverted(
            $this->contributionRows($user, $year, ContributionType::Social),
            $filingCurrency,
            $user->accountOwnerId(),
            $unconverted,
        );

        $healthPaid = $this->sumConverted(
            $this->contributionRows($user, $year, ContributionType::Health),
            $filingCurrency,
            $user->accountOwnerId(),
            $unconverted,
        );

        $advancesPaid = $this->sumConverted(
            $this->contributionRows($user, $year, ContributionType::IncomeTaxAdvance),
            $filingCurrency,
            $user->accountOwnerId(),
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
    private function businessIncomeRows(Account $user, int $year): Collection
    {
        return $this->fromInvoicing($this->cashBasis->collectedInYear($user->accountOwnerId(), $year));
    }

    /**
     * @return Collection<int, AggregatedAmountRow>
     */
    private function supplierInvoiceRows(Account $user, int $year): Collection
    {
        return $this->fromInvoicing($this->cashBasis->supplierInvoicesPaidInYear($user->accountOwnerId(), $year));
    }

    /**
     * @return Collection<int, AggregatedAmountRow>
     */
    private function expenseRows(Account $user, int $year): Collection
    {
        return $this->fromInvoicing($this->cashBasis->expensesInYear($user->accountOwnerId(), $year));
    }

    /**
     * @return Collection<int, AggregatedAmountRow>
     */
    private function standaloneCashRows(Account $user, int $year, CashDocumentType $type): Collection
    {
        return $this->fromInvoicing($this->cashBasis->standaloneCashInYear($user->accountOwnerId(), $year, $type));
    }

    /**
     * @param  list<DatedAmount>  $amounts
     * @return Collection<int, AggregatedAmountRow>
     */
    private function fromInvoicing(array $amounts): Collection
    {
        return new Collection(array_map(
            static fn (DatedAmount $amount): AggregatedAmountRow => new AggregatedAmountRow(
                amount: $amount->amount,
                currency: $amount->currency->value,
                date: $amount->date,
            ),
            $amounts,
        ));
    }

    /**
     * @return Collection<int, AggregatedAmountRow>
     */
    private function contributionRows(Account $user, int $year, ContributionType $type): Collection
    {
        return ContributionPayment::query()
            ->withoutGlobalScope('user')
            ->where('user_id', $user->accountOwnerId())
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
