<?php

declare(strict_types=1);

namespace Database\Factories\Modules\Pricing\Domain\Models;

use App\Modules\Auth\Domain\Models\User;
use App\Modules\Pricing\Domain\Enums\RateLevel;
use App\Modules\Pricing\Domain\Models\Rate;
use App\Modules\Shared\Enums\Currency;
use Database\Factories\Modules\Clients\Domain\Models\ClientFactory;
use Database\Factories\Modules\Orders\Domain\Models\OrderFactory;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Rate>
 */
class RateFactory extends Factory
{
    protected $model = Rate::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'level' => RateLevel::User->value,
            'client_id' => null,
            'order_id' => null,
            'rate' => fake()->randomFloat(2, 25, 150),
            'currency' => fake()->optional()->randomElement(Currency::cases())?->value,
            'valid_from' => fake()->dateTimeBetween('-1 year', 'now')->format('Y-m-d'),
            'note' => fake()->optional()->sentence(),
        ];
    }

    public function forClientLevel(): static
    {
        return $this->state(fn (array $attributes): array => [
            'level' => RateLevel::Client->value,
            'client_id' => ClientFactory::new(),
        ]);
    }

    public function forOrderLevel(): static
    {
        return $this->state(fn (array $attributes): array => [
            'level' => RateLevel::Order->value,
            'order_id' => OrderFactory::new(),
        ]);
    }
}
