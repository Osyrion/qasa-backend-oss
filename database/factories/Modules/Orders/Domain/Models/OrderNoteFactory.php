<?php

declare(strict_types=1);

namespace Database\Factories\Modules\Orders\Domain\Models;

use App\Modules\Auth\Domain\Models\User;
use App\Modules\Orders\Domain\Models\OrderNote;
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
            'order_id' => OrderFactory::new(),
            'user_id' => User::factory(),
            'content' => fake()->paragraph(),
        ];
    }
}
