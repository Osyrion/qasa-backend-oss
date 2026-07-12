<?php

declare(strict_types=1);

namespace Database\Factories\Modules\Invoicing\Domain\Models;

use App\Modules\Auth\Domain\Models\User;
use App\Modules\Invoicing\Domain\Models\PaymentOrder;
use App\Modules\Shared\Enums\Currency;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PaymentOrder>
 */
class PaymentOrderFactory extends Factory
{
    protected $model = PaymentOrder::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'bank_account_id' => null,
            'payer_snapshot' => [
                'label' => fake()->words(2, true),
                'bank_name' => fake()->company(),
                'account_number' => fake()->numerify('#########/0100'),
                'iban' => 'CZ'.fake()->numerify('####################'),
                'bic' => 'KOMBCZPP',
                'currency' => Currency::CZK->value,
            ],
            'currency' => Currency::CZK->value,
            'due_date' => now()->addDays(3)->toDateString(),
            'constant_symbol' => null,
            'note' => fake()->optional()->sentence(),
            'items_count' => 0,
            'total_amount' => 0,
            'marked_paid' => false,
        ];
    }
}
