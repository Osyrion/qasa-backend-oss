<?php

declare(strict_types=1);

namespace Database\Factories\Modules\Orders\Domain\Models;

use App\Modules\Orders\Domain\Models\OrderNote;
use Database\Factories\BoundAccount;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OrderNote>
 */
class OrderNoteFactory extends Factory
{
    protected $model = OrderNote::class;

    public function definition(): array
    {
        return [
            'order_id' => fn (array $attributes) => OrderFactory::new()->create(['user_id' => $attributes['user_id']]),
            'user_id' => fn (): string => BoundAccount::id(),
            'content' => fake()->paragraph(),
        ];
    }
}
