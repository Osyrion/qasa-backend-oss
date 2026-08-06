<?php

declare(strict_types=1);

namespace App\Modules\Taxation\Domain\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * An admin-editable override for one year of SkRateTable — see the
 * create_tax_rate_parameters_tables migration for why `year` is the primary
 * key and why there is no partial-row concept.
 *
 * @property int $year
 * @property float $flat_expense_rate
 * @property float $flat_expense_cap
 * @property float $low_rate_income_threshold
 * @property float $low_tax_rate
 * @property float $standard_tax_rate
 * @property float $high_tax_rate
 * @property float $high_rate_threshold
 * @property float $life_minimum
 * @property float $social_contribution_threshold
 * @property float $social_contribution_rate
 * @property float $health_contribution_rate
 * @property float $assessment_base_share
 * @property float $child_bonus_under_15
 * @property float $child_bonus_15_to_18
 * @property array{"1": float, "2": float, "3": float, "4": float, "5": float, "6": float} $child_bonus_cap_shares
 */
class SkTaxRateParameterSet extends Model
{
    protected $table = 'sk_tax_rate_parameters';

    protected $primaryKey = 'year';

    public $incrementing = false;

    protected $keyType = 'int';

    protected $fillable = [
        'year', 'flat_expense_rate', 'flat_expense_cap', 'low_rate_income_threshold',
        'low_tax_rate', 'standard_tax_rate', 'high_tax_rate', 'high_rate_threshold',
        'life_minimum', 'social_contribution_threshold', 'social_contribution_rate',
        'health_contribution_rate', 'assessment_base_share', 'child_bonus_under_15',
        'child_bonus_15_to_18', 'child_bonus_cap_shares',
    ];

    protected function casts(): array
    {
        return [
            'flat_expense_rate' => 'float',
            'flat_expense_cap' => 'float',
            'low_rate_income_threshold' => 'float',
            'low_tax_rate' => 'float',
            'standard_tax_rate' => 'float',
            'high_tax_rate' => 'float',
            'high_rate_threshold' => 'float',
            'life_minimum' => 'float',
            'social_contribution_threshold' => 'float',
            'social_contribution_rate' => 'float',
            'health_contribution_rate' => 'float',
            'assessment_base_share' => 'float',
            'child_bonus_under_15' => 'float',
            'child_bonus_15_to_18' => 'float',
            'child_bonus_cap_shares' => 'array',
        ];
    }
}
