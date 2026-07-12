<?php

declare(strict_types=1);

namespace Database\Factories\Modules\Invoicing\Domain\Models;

use App\Modules\Invoicing\Domain\Models\PaymentOrderItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PaymentOrderItem>
 */
class PaymentOrderItemFactory extends Factory
{
    protected $model = PaymentOrderItem::class;

    public function definition(): array
    {
        return [
            'payment_order_id' => PaymentOrderFactory::new(),
            'supplier_invoice_id' => null,
            'vendor_name' => fake()->company(),
            'supplier_invoice_number' => fake()->bothify('INV-####/??'),
            'account_number' => fake()->numerify('#########'),
            'bank_code' => '0100',
            'iban' => null,
            'bic' => null,
            'variable_symbol' => fake()->numerify('##########'),
            'amount' => fake()->randomFloat(2, 100, 10000),
            'sort_order' => 0,
        ];
    }
}
