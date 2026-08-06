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
        'childrenAges' => [5],
    ]));

    expect($result->childTaxBonus)->toBeLessThanOrEqual(round($result->partialTaxBaseBusiness * 0.20, 2));
});

/**
 * §33 ods. 6: the cap is a share of the partial tax base that widens with
 * the number of children — 20/27/34/41/48/55 % for one through six or more.
 * A single flat 20 % under-credits every family past the first child.
 */
it('widens the child bonus cap with the number of children', function (int $children, float $expectedShare): void {
    $calculator = new SkIncomeTaxReturnCalculator;

    $result = $calculator->calculate(skInput([
        // Low enough that the cap, not the per-child amount, is what binds.
        'systemIncome' => skSystemIncome(businessIncome: 5_000),
        'useActualExpenses' => true,
        'childrenAges' => array_fill(0, $children, 5),
    ]));

    expect($result->childTaxBonus)->toBe(round($result->partialTaxBaseBusiness * $expectedShare, 2));
})->with([
    'one child' => [1, 0.20],
    'two children' => [2, 0.27],
    'three children' => [3, 0.34],
    'four children' => [4, 0.41],
    'five children' => [5, 0.48],
    'six children' => [6, 0.55],
    'seven children stay at the six-child cap' => [7, 0.55],
]);

it('counts only bonus-eligible children towards the cap band', function (): void {
    $calculator = new SkIncomeTaxReturnCalculator;

    // Two eligible children and two adults: the band is the two-child 27 %,
    // not the four-"child" 41 %.
    $result = $calculator->calculate(skInput([
        'systemIncome' => skSystemIncome(businessIncome: 5_000),
        'useActualExpenses' => true,
        'childrenAges' => [5, 8, 22, 25],
    ]));

    expect($result->childTaxBonus)->toBe(round($result->partialTaxBaseBusiness * 0.27, 2));
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

/*
 * The 15% rate is gated on §6 business income but was applied to the whole
 * adjusted base, so a small side business dragged salary and other income
 * down to 15% with it — and the 25% bracket was skipped entirely on the way.
 * Every other test in this file passes employmentIncome: 0.0, which is why
 * it stood: the mixed case the wizard accepts was never exercised.
 */
it('does not extend the 15% business rate to employment income', function (): void {
    $result = (new SkIncomeTaxReturnCalculator)->calculate(skInput([
        'systemIncome' => skSystemIncome(businessIncome: 10_000),
        'employmentIncome' => 50_000.0,
    ]));

    // 60% flat rate on 10k leaves a 4k business partial base; the 50k salary
    // is untouched by it. NČZD is fully phased out at this base.
    expect($result->partialTaxBaseBusiness)->toBe(4_000.0)
        ->and($result->totalTaxBase)->toBe(54_000.0)
        ->and($result->taxCredits)->toBe(0.0);

    // 4k of business at 15%, the remaining 50k on the 19/25 scale.
    $expected = round(
        4_000.0 * 0.15
        + 48_441.43 * 0.19
        + (50_000.0 - 48_441.43) * 0.25,
        2,
    );

    expect($result->taxBeforeCredits)->toBe($expected);
});

it('taxes a pure employment base on the 19/25 scale with no business income', function (): void {
    $result = (new SkIncomeTaxReturnCalculator)->calculate(skInput([
        'systemIncome' => skSystemIncome(businessIncome: 0.0),
        'employmentIncome' => 60_000.0,
    ]));

    $base = $result->totalTaxBase - $result->taxCredits;

    expect($result->taxBeforeCredits)->toBe(round(
        48_441.43 * 0.19 + ($base - 48_441.43) * 0.25,
        2,
    ));
});

/*
 * The assessment base is a flat share of the partial tax base, bounded at
 * neither end, while SK law bounds it at both. €5k of income produces €140 of
 * annual health contributions (below the statutory minimum) and €400k produces
 * €89,490 in total (no ceiling applied). The figures needed to fix it belong
 * in SkRateTable and have to come off the official tables first — until then
 * the result says so, rather than presenting the number as a liability.
 */
it('flags that the contribution estimate ignores the statutory assessment bounds', function (): void {
    $result = (new SkIncomeTaxReturnCalculator)->calculate(skInput([
        'systemIncome' => skSystemIncome(businessIncome: 400_000),
    ]));

    expect($result->notes)->toContain(__('taxation.sk_contributions_unbounded_note'));
});

it('does not flag the contribution estimate when nothing is owed', function (): void {
    $result = (new SkIncomeTaxReturnCalculator)->calculate(skInput([
        'systemIncome' => skSystemIncome(businessIncome: 0.0),
        'monthsActive' => 0,
    ]));

    expect($result->notes)->not->toContain(__('taxation.sk_contributions_unbounded_note'));
});
