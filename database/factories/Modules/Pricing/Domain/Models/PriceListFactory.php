<?php

declare(strict_types=1);

namespace Database\Factories\Modules\Pricing\Domain\Models;

use App\Modules\Auth\Domain\Models\User;
use App\Modules\Pricing\Domain\Models\PriceList;
use App\Modules\Shared\Enums\Currency;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PriceList>
 */
class PriceListFactory extends Factory
{
    protected $model = PriceList::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'name' => fake()->unique()->words(3, true),
            'description' => fake()->optional()->paragraph(),
            'currency' => fake()->optional()->randomElement(Currency::cases())?->value,
            'country' => fake()->optional()->randomElement(['SK', 'CZ', 'AT', 'DE']),
            'is_default' => false,
        ];
    }

    public function default(): static
    {
        return $this->state(fn (array $attributes): array => [
            'is_default' => true,
        ]);
    }
}
