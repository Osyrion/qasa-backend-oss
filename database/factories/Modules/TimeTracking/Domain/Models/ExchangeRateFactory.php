<?php

declare(strict_types=1);

namespace Database\Factories\Modules\TimeTracking\Domain\Models;

use App\Modules\Auth\Domain\Models\User;
use App\Modules\Shared\Enums\Currency;
use App\Modules\TimeTracking\Domain\Models\ExchangeRate;
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
            'user_id' => fake()->optional()->passthrough(User::factory()),
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
