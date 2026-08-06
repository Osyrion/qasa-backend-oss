<?php

declare(strict_types=1);

namespace App\Modules\Taxation\Domain\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * An admin-editable override for one year of CzRateTable — see the
 * create_tax_rate_parameters_tables migration for why `year` is the primary
 * key and why there is no partial-row concept.
 *
 * @property int $year
 * @property float $low_tax_rate
 * @property float $high_tax_rate
 * @property float $high_rate_threshold
 * @property float $basic_taxpayer_credit
 * @property float $spouse_credit
 * @property array{"1": float, "2": float, "3": float} $child_credits
 * @property array{"80": float, "60": float, "40": float, "30": float} $flat_rate_income_caps
 * @property float $social_contribution_rate
 * @property float $health_contribution_rate
 * @property float $assessment_base_share
 * @property float $min_monthly_social_base_main_activity
 * @property float $min_monthly_health_base_main_activity
 * @property float $secondary_activity_threshold
 */
class CzTaxRateParameterSet extends Model
{
    protected $table = 'cz_tax_rate_parameters';

    protected $primaryKey = 'year';

    public $incrementing = false;

    protected $keyType = 'int';

    protected $fillable = [
        'year', 'low_tax_rate', 'high_tax_rate', 'high_rate_threshold',
        'basic_taxpayer_credit', 'spouse_credit', 'child_credits', 'flat_rate_income_caps',
        'social_contribution_rate', 'health_contribution_rate', 'assessment_base_share',
        'min_monthly_social_base_main_activity', 'min_monthly_health_base_main_activity',
        'secondary_activity_threshold',
    ];

    protected function casts(): array
    {
        return [
            'low_tax_rate' => 'float',
            'high_tax_rate' => 'float',
            'high_rate_threshold' => 'float',
            'basic_taxpayer_credit' => 'float',
            'spouse_credit' => 'float',
            'child_credits' => 'array',
            'flat_rate_income_caps' => 'array',
            'social_contribution_rate' => 'float',
            'health_contribution_rate' => 'float',
            'assessment_base_share' => 'float',
            'min_monthly_social_base_main_activity' => 'float',
            'min_monthly_health_base_main_activity' => 'float',
            'secondary_activity_threshold' => 'float',
        ];
    }
}
