<?php

declare(strict_types=1);

use App\Modules\Taxation\Domain\Models\CzTaxRateParameterSet;
use App\Modules\Taxation\Domain\Models\SkTaxRateParameterSet;
use App\Modules\Taxation\Infrastructure\Cz\CzIncomeTaxReturnCalculator;
use App\Modules\Taxation\Infrastructure\Cz\Rates\DbBackedCzRateTable;
use App\Modules\Taxation\Infrastructure\Sk\Rates\DbBackedSkRateTable;
use App\Modules\Taxation\Infrastructure\Sk\SkIncomeTaxReturnCalculator;

it('has no DB row for a hardcoded year until one is written', function (): void {
    expect(DbBackedSkRateTable::forYear(2025))->toBeNull()
        ->and(DbBackedSkRateTable::hasYear(2025))->toBeFalse()
        ->and(DbBackedCzRateTable::forYear(2025))->toBeNull();
});

it('still supports a hardcoded year with no DB override', function (): void {
    expect((new SkIncomeTaxReturnCalculator)->supportsYear(2025))->toBeTrue()
        ->and((new CzIncomeTaxReturnCalculator)->supportsYear(2025))->toBeTrue();
});

it('does not support an unlisted year with no DB override', function (): void {
    expect((new SkIncomeTaxReturnCalculator)->supportsYear(2030))->toBeFalse();
});

it('starts supporting a year once an admin override row exists for it', function (): void {
    SkTaxRateParameterSet::query()->create([
        'year' => 2030,
        'flat_expense_rate' => 0.60,
        'flat_expense_cap' => 20_000.0,
        'low_rate_income_threshold' => 60_000.0,
        'low_tax_rate' => 0.15,
        'standard_tax_rate' => 0.19,
        'high_tax_rate' => 0.25,
        'high_rate_threshold' => 60_000.0,
        'life_minimum' => 300.0,
        'social_contribution_threshold' => 8_000.0,
        'social_contribution_rate' => 0.331,
        'health_contribution_rate' => 0.14,
        'assessment_base_share' => 0.5,
        'child_bonus_under_15' => 1_700.0,
        'child_bonus_15_to_18' => 620.0,
        'child_bonus_cap_shares' => [1 => 0.20, 2 => 0.27, 3 => 0.34, 4 => 0.41, 5 => 0.48, 6 => 0.55],
    ]);

    expect((new SkIncomeTaxReturnCalculator)->supportsYear(2030))->toBeTrue();

    $rates = DbBackedSkRateTable::forYear(2030);
    expect($rates)->not->toBeNull();
    assert($rates instanceof DbBackedSkRateTable);

    expect($rates->highRateThreshold())->toBe(60_000.0)
        ->and($rates->childBonusCapShare(2))->toBe(0.27);
});

it('prefers the DB row over the hardcoded class for a year both define', function (): void {
    SkTaxRateParameterSet::query()->create([
        'year' => 2025,
        'flat_expense_rate' => 0.60,
        'flat_expense_cap' => 20_000.0,
        'low_rate_income_threshold' => 60_000.0,
        'low_tax_rate' => 0.15,
        'standard_tax_rate' => 0.19,
        'high_tax_rate' => 0.25,
        'high_rate_threshold' => 12_345.0,
        'life_minimum' => 273.99,
        'social_contribution_threshold' => 7_824.0,
        'social_contribution_rate' => 0.331,
        'health_contribution_rate' => 0.14,
        'assessment_base_share' => 0.5,
        'child_bonus_under_15' => 1_680.0,
        'child_bonus_15_to_18' => 600.0,
        'child_bonus_cap_shares' => [1 => 0.20, 2 => 0.27, 3 => 0.34, 4 => 0.41, 5 => 0.48, 6 => 0.55],
    ]);

    $rates = DbBackedSkRateTable::forYear(2025);
    expect($rates)->not->toBeNull();
    assert($rates instanceof DbBackedSkRateTable);

    expect($rates->highRateThreshold())->toBe(12_345.0);
});

it('reads CZ map-shaped fields back with the right key types', function (): void {
    CzTaxRateParameterSet::query()->create([
        'year' => 2030,
        'low_tax_rate' => 0.15,
        'high_tax_rate' => 0.23,
        'high_rate_threshold' => 1_700_000.0,
        'basic_taxpayer_credit' => 30_840.0,
        'spouse_credit' => 24_840.0,
        'child_credits' => [1 => 15_204.0, 2 => 22_320.0, 3 => 27_840.0],
        'flat_rate_income_caps' => [80 => 1_000_000.0, 60 => 1_500_000.0, 40 => 2_000_000.0, 30 => 2_000_000.0],
        'social_contribution_rate' => 0.292,
        'health_contribution_rate' => 0.135,
        'assessment_base_share' => 0.5,
        'min_monthly_social_base_main_activity' => 12_527.0,
        'min_monthly_health_base_main_activity' => 21_982.0,
        'secondary_activity_threshold' => 105_520.0,
    ]);

    $rates = DbBackedCzRateTable::forYear(2030);
    expect($rates)->not->toBeNull();
    assert($rates instanceof DbBackedCzRateTable);

    expect($rates->childCredits())->toBe([1 => 15_204.0, 2 => 22_320.0, 3 => 27_840.0])
        ->and($rates->flatRateIncomeCaps())->toBe([80 => 1_000_000.0, 60 => 1_500_000.0, 40 => 2_000_000.0, 30 => 2_000_000.0]);
});
