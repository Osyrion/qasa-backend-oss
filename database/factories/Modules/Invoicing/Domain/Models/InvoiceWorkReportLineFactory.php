<?php

declare(strict_types=1);

namespace Database\Factories\Modules\Invoicing\Domain\Models;

use App\Modules\Invoicing\Domain\Models\InvoiceWorkReportLine;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InvoiceWorkReportLine>
 */
class InvoiceWorkReportLineFactory extends Factory
{
    protected $model = InvoiceWorkReportLine::class;

    public function definition(): array
    {
        return [
            'invoice_id' => InvoiceFactory::new(),
            'time_entry_id' => null,
            'work_date' => fake()->dateTimeBetween('-1 month', 'now'),
            'description' => fake()->sentence(4),
            'hours' => fake()->randomFloat(2, 0.5, 8),
            'sort_order' => 0,
        ];
    }
}
