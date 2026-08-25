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
            //
            // Always the bound account, never optional(): a NULL user_id is a
            // *system* rate, which no tenant connection may write since
            // 2026_08_19_000002_restrict_system_exchange_rate_writes. Leaving
            // it to chance made half of every unqualified create() a row the
            // policy rejects. The system rows have their own way in — the
            // systemExchangeRate() helper in tests/Pest.php.
            'user_id' => BoundAccount::id(),
            'base_currency' => $baseCurrency->value,
            'target_currency' => $targetCurrency->value,
            'rate' => fake()->randomFloat(6, 0.02, 30),
            'date' => fake()->dateTimeBetween('-1 year', 'now'),
            'source' => fake()->randomElement(['manual', 'ecb', 'fixer']),
        ];
    }
}
