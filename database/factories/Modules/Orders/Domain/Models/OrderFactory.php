<?php

declare(strict_types=1);

namespace Database\Factories\Modules\Orders\Domain\Models;

use App\Modules\Auth\Domain\Models\User;
use App\Modules\Orders\Domain\Enums\OrderStatus;
use App\Modules\Orders\Domain\Models\Order;
use App\Modules\Shared\Enums\BillingType;
use App\Modules\Shared\Enums\Currency;
use Database\Factories\Modules\Clients\Domain\Models\ClientFactory;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Order>
 */
class OrderFactory extends Factory
{
    protected $model = Order::class;

    public function definition(): array
    {
        $billingType = fake()->randomElement(BillingType::cases());

        return [
            'user_id' => User::factory(),
            'client_id' => null,
            'name' => fake()->sentence(3),
            'color' => fake()->hexColor(),
            'readme' => fake()->optional()->paragraph(),
            'status' => fake()->randomElement(OrderStatus::cases())->value,
            'billing_type' => $billingType->value,
            'rate' => $billingType->hasDefaultRate() ? fake()->randomFloat(2, 25, 150) : null,
            'currency' => fake()->optional()->randomElement(Currency::cases())?->value,
            'estimated_hours' => fake()->optional()->randomFloat(2, 1, 120),
            'estimated_price' => fake()->optional()->randomFloat(2, 100, 10000),
            'deadline' => fake()->optional()->dateTimeBetween('now', '+6 months'),
        ];
    }

    public function billable(): static
    {
        return $this->state(fn (array $attributes): array => [
            'client_id' => ClientFactory::new(),
        ]);
    }

    public function active(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => OrderStatus::Active->value,
        ]);
    }
}
