<?php

declare(strict_types=1);

namespace App\Modules\Taxation\Application\DTOs;

use App\Modules\Taxation\Application\Rules\RequiresPeriodScope;
use App\Modules\Taxation\Domain\Enums\TaxFilingType;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Spatie\LaravelData\Data;

class GenerateTaxFilingData extends Data
{
    public function __construct(
        public readonly TaxFilingType $type,
        public readonly int $period_year,
        public readonly ?int $period_quarter = null,
        public readonly ?int $period_month = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        return [
            'type' => ['required', Rule::enum(TaxFilingType::class), new RequiresPeriodScope],
            'period_year' => ['required', 'integer', 'min:2000', 'max:2100'],
            'period_quarter' => ['nullable', 'integer', 'between:1,4'],
            'period_month' => ['nullable', 'integer', 'between:1,12'],
        ];
    }

    public static function fromRequest(Request $request): self
    {
        return new self(
            type: TaxFilingType::from($request->string('type')->toString()),
            period_year: $request->integer('period_year'),
            period_quarter: $request->filled('period_quarter') ? $request->integer('period_quarter') : null,
            period_month: $request->filled('period_month') ? $request->integer('period_month') : null,
        );
    }
}
