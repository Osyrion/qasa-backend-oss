<?php

declare(strict_types=1);

use App\Modules\Shared\Enums\Currency;
use App\Modules\Taxation\Application\DTOs\SystemIncomeData;
use App\Modules\Taxation\Domain\ValueObjects\TaxReturnInput;
use App\Modules\Taxation\Infrastructure\Sk\SkIncomeTaxReturnCalculator;

function skSystemIncome(float $businessIncome, float $actualExpenses = 0.0, float $socialPaid = 0.0, float $healthPaid = 0.0): SystemIncomeData
{
    return new SystemIncomeData(
        year: 2025,
        currency: Currency::EUR,
        businessIncome: $businessIncome,
        supplierInvoiceExpenses: $actualExpenses,
        otherExpenses: 0.0,
        socialContributionsPaid: $socialPaid,
        healthContributionsPaid: $healthPaid,
        incomeTaxAdvancesPaid: 0.0,
    );
}

/**
 * @param  array<string, mixed>  $overrides
 */
function skInput(array $overrides = []): TaxReturnInput
{
    $defaults = [
        'year' => 2025,
        'systemIncome' => skSystemIncome(30_000),
        'useActualExpenses' => false,
        'flatRateCategoryPercent' => null,
        'isMainActivity' => true,
        'monthsActive' => 12,
        'childrenAges' => [],
        'spouseEligibleForCredit' => false,
        'employmentIncome' => 0.0,
        'otherIncome' => 0.0,
        'foreignIncome' => 0.0,
    ];

    $merged = [...$defaults, ...$overrides];

    return new TaxReturnInput(...$merged);
}

it('rejects an unsupported year', function (): void {
    $calculator = new SkIncomeTaxReturnCalculator;

    expect($calculator->supportsYear(2025))->toBeTrue()
        ->and($calculator->supportsYear(2026))->toBeTrue()
        ->and($calculator->supportsYear(2019))->toBeFalse();
});

it('computes flat-rate expenses at 60% plus contributions actually paid, capped at €20,000', function (): void {
    $calculator = new SkIncomeTaxReturnCalculator;

    $result = $calculator->calculate(skInput([
        'systemIncome' => skSystemIncome(businessIncome: 20_000, socialPaid: 1_000, healthPaid: 800),
        'useActualExpenses' => false,
    ]));

    // 60% of 20,000 = 12,000, plus 1,800 contributions = 13,800.
    expect($result->expensesUsed)->toBe(13_800.0)
        ->and($result->usedFlatRateExpenses)->toBeTrue();
});

it('caps the flat expense rate at the annual ceiling for high income', function (): void {
    $calculator = new SkIncomeTaxReturnCalculator;

    $result = $calculator->calculate(skInput([
        'systemIncome' => skSystemIncome(businessIncome: 100_000),
        'useActualExpenses' => false,
    ]));

    // 60% of 100,000 = 60,000, capped at 20,000.
    expect($result->expensesUsed)->toBe(20_000.0);
});

it('applies the 15% simplified rate for gross income at or under €60,000', function (): void {
    $calculator = new SkIncomeTaxReturnCalculator;

    $result = $calculator->calculate(skInput([
        'systemIncome' => skSystemIncome(businessIncome: 40_000),
        'useActualExpenses' => false,
    ]));

    $expectedBase = round($result->totalTaxBase - $result->taxCredits, 2);
    expect($result->taxBeforeCredits)->toBe(round($expectedBase * 0.15, 2));
});

it('applies the progressive 19/25% rate for gross income above €60,000', function (): void {
    $calculator = new SkIncomeTaxReturnCalculator;

    $result = $calculator->calculate(skInput([
        'systemIncome' => skSystemIncome(businessIncome: 200_000, actualExpenses: 20_000),
        'useActualExpenses' => true,
    ]));

    $adjustedBase = round($result->totalTaxBase - $result->taxCredits, 2);
    expect($result->taxBeforeCredits)->toBeGreaterThan(round($adjustedBase * 0.19, 2))
        ->and($result->taxBeforeCredits)->toBeLessThan(round($adjustedBase * 0.25, 2));
});

it('grants the full basic NČZD below the phase-out threshold and less above it', function (): void {
    $calculator = new SkIncomeTaxReturnCalculator;

    $low = $calculator->calculate(skInput(['systemIncome' => skSystemIncome(businessIncome: 15_000), 'useActualExpenses' => true]));
    $high = $calculator->calculate(skInput(['systemIncome' => skSystemIncome(businessIncome: 100_000, actualExpenses: 10_000), 'useActualExpenses' => true]));

    expect($low->taxCredits)->toBeGreaterThan($high->taxCredits);
});

it('has no social contribution obligation under the income threshold', function (): void {
    $calculator = new SkIncomeTaxReturnCalculator;

    $result = $calculator->calculate(skInput([
        'systemIncome' => skSystemIncome(businessIncome: 5_000),
        'useActualExpenses' => true,
    ]));

    expect($result->contributions['social'])->toBe(0.0);
});

it('caps the child bonus at a share of the business partial tax base', function (): void {
    $calculator = new SkIncomeTaxReturnCalculator;

    $result = $calculator->calculate(skInput([
        'systemIncome' => skSystemIncome(businessIncome: 5_000),
        'useActualExpenses' => true,
        'childrenAges' => [5, 8, 10, 12, 14],
    ]));

    expect($result->childTaxBonus)->toBeLessThanOrEqual(round($result->partialTaxBaseBusiness * 0.20, 2));
});

it('ignores a child older than 18 for the bonus', function (): void {
    $calculator = new SkIncomeTaxReturnCalculator;

    $withAdultChild = $calculator->calculate(skInput([
        'systemIncome' => skSystemIncome(businessIncome: 50_000),
        'useActualExpenses' => true,
        'childrenAges' => [20],
    ]));

    expect($withAdultChild->childTaxBonus)->toBe(0.0);
});

it('grants a larger spouse allowance only when eligible', function (): void {
    $calculator = new SkIncomeTaxReturnCalculator;

    $withoutSpouse = $calculator->calculate(skInput(['systemIncome' => skSystemIncome(businessIncome: 30_000), 'useActualExpenses' => true]));
    $withSpouse = $calculator->calculate(skInput([
        'systemIncome' => skSystemIncome(businessIncome: 30_000),
        'useActualExpenses' => true,
        'spouseEligibleForCredit' => true,
    ]));

    expect($withSpouse->taxCredits)->toBeGreaterThan($withoutSpouse->taxCredits)
        ->and($withSpouse->finalTax)->toBeLessThan($withoutSpouse->finalTax);
});
