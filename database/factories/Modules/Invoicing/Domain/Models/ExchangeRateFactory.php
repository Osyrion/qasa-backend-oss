<?php

declare(strict_types=1);

namespace Database\Factories\Modules\Invoicing\Domain\Models;

use App\Modules\Invoicing\Domain\Models\ExchangeRate;
use App\Modules\Shared\Enums\Currency;
use Database\Factories\BoundAccount;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ExchangeRate>
 */
class ExchangeRateFactory extends Factory
{
    protected $model = ExchangeRate::class;

    public function definition(): array
    {
        $baseCurrency = fake()->randomElement(Currency::cases());
        $targetCurrency = fake()->randomElement(
            array_values(array_filter(
                Currency::cases(),
                fn (Currency $currency): bool => $currency !== $baseCurrency,
            )),
        );

        return [
            // User::factory() as a bare relation default left the account it
            // created unbound — users is tenant-scoped now too (phase 7).
            'user_id' => fake()->optional()->passthrough(fn (): string => BoundAccount::id()),
            'base_currency' => $baseCurrency->value,
            'target_currency' => $targetCurrency->value,
            'rate' => fake()->randomFloat(6, 0.02, 30),
            'date' => fake()->dateTimeBetween('-1 year', 'now'),
            'source' => fake()->randomElement(['manual', 'ecb', 'fixer']),
        ];
    }

    public function system(): static
    {
        return $this->state(fn (array $attributes): array => [
            'user_id' => null,
        ]);
    }
}
