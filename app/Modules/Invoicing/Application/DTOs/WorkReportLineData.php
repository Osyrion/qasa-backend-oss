<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Application\DTOs;

use Spatie\LaravelData\Data;

class WorkReportLineData extends Data
{
    public function __construct(
        public readonly string $work_date,
        public readonly string $description,
        public readonly float $hours,
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
        ];
    }
}
