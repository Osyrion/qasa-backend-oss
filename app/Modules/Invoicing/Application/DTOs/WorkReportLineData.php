<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Application\DTOs;

use Spatie\LaravelData\Attributes\Validation\Nullable;
use Spatie\LaravelData\Data;

class WorkReportLineData extends Data
{
    public function __construct(
        public readonly string $work_date,
        public readonly string $description,
        public readonly float $hours,

        #[Nullable]
        public readonly ?string $time_entry_id = null,
    ) {}

    /**
     * Rules for a bulk `lines` payload (PUT invoices/{invoice}/work-report).
     *
     * @return array<string, mixed>
     */
    public static function bulkRules(): array
    {
        return [
            'lines' => ['present', 'array', 'max:200'],
            'lines.*.work_date' => ['required', 'date'],
            'lines.*.description' => ['required', 'string', 'max:255'],
            'lines.*.hours' => ['required', 'numeric', 'min:0', 'max:1000'],
            'lines.*.time_entry_id' => ['nullable', 'uuid', 'exists:time_entries,id'],
        ];
    }
}
