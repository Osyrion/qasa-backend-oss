<?php

declare(strict_types=1);

namespace Database\Factories\Modules\Invoicing\Domain\Models;

use App\Modules\Invoicing\Domain\Models\InvoicePayment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InvoicePayment>
 */
class InvoicePaymentFactory extends Factory
{
    protected $model = InvoicePayment::class;

    public function definition(): array
    {
        return [
            'invoice_id' => InvoiceFactory::new(),
            'amount' => fake()->randomFloat(2, 10, 5000),
            'paid_at' => fake()->dateTimeBetween('-1 month', 'now'),
            'method' => fake()->randomElement(['bank_transfer', 'cash', 'card', 'other']),
            'note' => fake()->optional()->sentence(),
        ];
    }
}
