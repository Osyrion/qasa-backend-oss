<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Application\DTOs;

use App\Modules\Invoicing\Domain\Enums\ExportPeriodBasis;
use Illuminate\Validation\Rule;
use Spatie\LaravelData\Data;

class SupplierInvoiceExportData extends Data
{
    public function __construct(
        public readonly string $date_from,
        public readonly string $date_to,
        public readonly ExportPeriodBasis $period_basis = ExportPeriodBasis::Issue,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        return [
            'date_from' => ['required', 'date'],
            'date_to' => ['required', 'date', 'after_or_equal:date_from'],
            'period_basis' => ['sometimes', Rule::enum(ExportPeriodBasis::class)],
        ];
    }
}
