<?php

declare(strict_types=1);

namespace Database\Factories\Modules\Invoicing\Domain\Models;

use App\Modules\Invoicing\Domain\Models\RecurringInvoiceTemplateItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RecurringInvoiceTemplateItem>
 */
class RecurringInvoiceTemplateItemFactory extends Factory
{
    protected $model = RecurringInvoiceTemplateItem::class;

    public function definition(): array
    {
        return [
            'template_id' => RecurringInvoiceTemplateFactory::new(),
            'description' => fake()->sentence(3),
            'quantity' => fake()->randomFloat(3, 1, 10),
            'unit' => 'ks',
            'unit_price' => fake()->randomFloat(2, 100, 5000),
            'vat_rate' => fake()->randomElement([0, 21]),
            'sort_order' => 0,
        ];
    }
}
