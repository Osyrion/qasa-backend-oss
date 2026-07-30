<?php

declare(strict_types=1);

namespace App\Modules\Taxation\Application\DTOs;

use Illuminate\Validation\Rule;
use Spatie\LaravelData\Attributes\Validation\Nullable;
use Spatie\LaravelData\Data;

/**
 * The wizard's personal-circumstances answers — what the system can't
 * derive from documents on file. One shared shape for both SK/CZ;
 * flat_rate_category_percent is CZ-only (SK's flat rate is a fixed 60%, see
 * SkIncomeTaxReturnCalculator) and simply ignored by the SK calculator.
 */
class TaxReturnInputData extends Data
{
    public function __construct(
        public readonly int $year,
        public readonly bool $use_actual_expenses,
        public readonly bool $is_main_activity,
        public readonly int $months_active,
        public readonly bool $spouse_eligible_for_credit,

        #[Nullable]
        public readonly ?int $flat_rate_category_percent = null,

        /** @var list<int> */
        public readonly array $children_ages = [],

        public readonly float $employment_income = 0.0,
        public readonly float $other_income = 0.0,
        public readonly float $foreign_income = 0.0,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        return [
            'year' => ['integer', 'min:2000', 'max:2100'],
            'months_active' => ['integer', 'min:0', 'max:12'],
            'flat_rate_category_percent' => ['nullable', 'integer', Rule::in([80, 60, 40, 30])],
            'children_ages' => ['array'],
            'children_ages.*' => ['integer', 'min:0', 'max:30'],
            'employment_income' => ['numeric', 'min:0'],
            'other_income' => ['numeric', 'min:0'],
            'foreign_income' => ['numeric', 'min:0'],
        ];
    }
}
