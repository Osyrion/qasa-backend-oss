<?php

declare(strict_types=1);

namespace Database\Factories\Modules\Invoicing\Domain\Models;

use App\Modules\Auth\Domain\Models\User;
use App\Modules\Invoicing\Domain\Models\BankAccount;
use App\Modules\Shared\Enums\Currency;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BankAccount>
 */
class BankAccountFactory extends Factory
{
    protected $model = BankAccount::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'label' => fake()->words(2, true),
            'bank_name' => fake()->company(),
            'account_number' => fake()->numerify('#########/0100'),
            'iban' => 'CZ'.fake()->numerify('####################'),
            'bic' => 'KOMBCZPP',
            'currency' => fake()->randomElement(Currency::cases())->value,
            'is_default' => false,
        ];
    }

    public function default(): static
    {
        return $this->state(fn (array $attributes): array => [
            'is_default' => true,
        ]);
    }

    public function currency(Currency $currency): static
    {
        return $this->state(fn (array $attributes): array => [
            'currency' => $currency->value,
        ]);
    }
}
