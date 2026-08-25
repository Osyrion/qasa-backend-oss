<?php

declare(strict_types=1);

use App\Modules\Shared\Enums\Currency;
use App\Modules\Shared\Exceptions\DomainException;
use App\Modules\Taxation\Domain\ValueObjects\SystemIncomeData;
use App\Modules\Taxation\Domain\ValueObjects\TaxReturnInput;
use App\Modules\Taxation\Infrastructure\Cz\CzIncomeTaxReturnCalculator;
use App\Modules\Taxation\Infrastructure\Rates\ConfiguredRateTables;

function czSystemIncome(float $businessIncome, float $actualExpenses = 0.0): SystemIncomeData
{
    return new SystemIncomeData(
        year: 2025,
        currency: Currency::CZK,
        businessIncome: $businessIncome,
        supplierInvoiceExpenses: $actualExpenses,
        otherExpenses: 0.0,
        socialContributionsPaid: 0.0,
        healthContributionsPaid: 0.0,
        incomeTaxAdvancesPaid: 0.0,
    );
}

/**
 * @param  array<string, mixed>  $overrides
 */
function czInput(array $overrides = []): TaxReturnInput
{
    $defaults = [
        'year' => 2025,
        'systemIncome' => czSystemIncome(500_000),
        'useActualExpenses' => false,
        'flatRateCategoryPercent' => 60,
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
    $calculator = new CzIncomeTaxReturnCalculator(new ConfiguredRateTables);

    expect($calculator->supportsYear(2025))->toBeTrue()
        ->and($calculator->supportsYear(2026))->toBeTrue()
        ->and($calculator->supportsYear(2020))->toBeFalse();

    $calculator->calculate(czInput(['year' => 2020, 'systemIncome' => new SystemIncomeData(
        year: 2020, currency: Currency::CZK, businessIncome: 100_000, supplierInvoiceExpenses: 0,
        otherExpenses: 0, socialContributionsPaid: 0, healthContributionsPaid: 0, incomeTaxAdvancesPaid: 0,
    )]));
})->throws(DomainException::class);

it('computes flat-rate expenses at 60%, capped by the income cap', function (): void {
    $calculator = new CzIncomeTaxReturnCalculator(new ConfiguredRateTables);

    $result = $calculator->calculate(czInput([
        'systemIncome' => czSystemIncome(500_000),
        'useActualExpenses' => false,
        'flatRateCategoryPercent' => 60,
    ]));

    expect($result->expensesUsed)->toBe(300_000.0)
        ->and($result->usedFlatRateExpenses)->toBeTrue()
        ->and($result->partialTaxBaseBusiness)->toBe(200_000.0);
});

it('zeroes out tax owed when the basic taxpayer credit exceeds the computed tax', function (): void {
    $calculator = new CzIncomeTaxReturnCalculator(new ConfiguredRateTables);

    $result = $calculator->calculate(czInput([
        'systemIncome' => czSystemIncome(100_000),
        'useActualExpenses' => true,
    ]));

    expect($result->taxBeforeCredits)->toBe(15_000.0)
        ->and($result->taxCredits)->toBe(30_840.0)
        ->and($result->finalTax)->toBe(0.0)
        ->and($result->childTaxBonus)->toBe(0.0);
});

it('applies the 23% bracket only to the portion of the base above the threshold', function (): void {
    $calculator = new CzIncomeTaxReturnCalculator(new ConfiguredRateTables);

    $belowThreshold = $calculator->calculate(czInput([
        'systemIncome' => czSystemIncome(2_000_000, 1_000_000),
        'useActualExpenses' => true,
    ]));

    $aboveThreshold = $calculator->calculate(czInput([
        'systemIncome' => czSystemIncome(5_000_000, 500_000),
        'useActualExpenses' => true,
    ]));

    // Below the ~1.68M threshold, only the 15% bracket applies.
    expect($belowThreshold->taxBeforeCredits)->toBe(round($belowThreshold->totalTaxBase * 0.15, 2));
    // Above it, blended effective rate exceeds the flat 15%.
    expect($aboveThreshold->taxBeforeCredits / $aboveThreshold->totalTaxBase)->toBeGreaterThan(0.15);
});

it('has no social contribution obligation for a secondary activity under the threshold', function (): void {
    $calculator = new CzIncomeTaxReturnCalculator(new ConfiguredRateTables);

    $result = $calculator->calculate(czInput([
        'systemIncome' => czSystemIncome(80_000),
        'useActualExpenses' => true,
        'isMainActivity' => false,
    ]));

    expect($result->contributions['social'])->toBe(0.0)
        ->and($result->contributions['health'])->toBe(0.0);
});

it('applies the minimum assessment base for a main activity regardless of low income', function (): void {
    $calculator = new CzIncomeTaxReturnCalculator(new ConfiguredRateTables);

    $result = $calculator->calculate(czInput([
        'systemIncome' => czSystemIncome(50_000),
        'useActualExpenses' => true,
        'isMainActivity' => true,
    ]));

    expect($result->contributions['social'])->toBeGreaterThan(0.0)
        ->and($result->contributions['health'])->toBeGreaterThan(0.0);
});

it('reduces final tax by the per-child credit, ordered by birth order', function (): void {
    $calculator = new CzIncomeTaxReturnCalculator(new ConfiguredRateTables);

    $noChildren = $calculator->calculate(czInput(['systemIncome' => czSystemIncome(1_000_000, 200_000), 'useActualExpenses' => true]));
    $twoChildren = $calculator->calculate(czInput([
        'systemIncome' => czSystemIncome(1_000_000, 200_000),
        'useActualExpenses' => true,
        'childrenAges' => [10, 16],
    ]));

    expect($twoChildren->childTaxBonus)->toBe(15_204.0 + 22_320.0)
        ->and($twoChildren->finalTax)->toBe(round($noChildren->finalTax - $twoChildren->childTaxBonus, 2));
});

it('prorates contributions by months active', function (): void {
    $calculator = new CzIncomeTaxReturnCalculator(new ConfiguredRateTables);

    $fullYear = $calculator->calculate(czInput([
        'systemIncome' => czSystemIncome(2_000_000, 500_000),
        'useActualExpenses' => true,
        'monthsActive' => 12,
    ]));

    $halfYear = $calculator->calculate(czInput([
        'systemIncome' => czSystemIncome(2_000_000, 500_000),
        'useActualExpenses' => true,
        'monthsActive' => 6,
    ]));

    expect($halfYear->contributions['social'])->toBe(round($fullYear->contributions['social'] / 2, 2))
        ->and($halfYear->contributions['health'])->toBe(round($fullYear->contributions['health'] / 2, 2));
});

it('surfaces a foreign income note without including it in the tax base', function (): void {
    $calculator = new CzIncomeTaxReturnCalculator(new ConfiguredRateTables);

    $result = $calculator->calculate(czInput([
        'systemIncome' => czSystemIncome(500_000, 300_000),
        'useActualExpenses' => true,
        'foreignIncome' => 100_000,
    ]));

    expect($result->notes)->toContain(__('taxation.foreign_income_note'))
        ->and($result->totalTaxBase)->toBe(200_000.0);
});
