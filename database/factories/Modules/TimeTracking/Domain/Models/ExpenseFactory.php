<?php

declare(strict_types=1);

namespace Database\Factories\Modules\TimeTracking\Domain\Models;

use App\Modules\Auth\Domain\Models\User;
use App\Modules\Shared\Enums\Currency;
use App\Modules\TimeTracking\Domain\Enums\ExpenseCategory;
use App\Modules\TimeTracking\Domain\Models\Expense;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Expense>
 */
class ExpenseFactory extends Factory
{
    protected $model = Expense::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'description' => fake()->sentence(3),
            'category' => fake()->randomElement(ExpenseCategory::cases())->value,
            'amount' => fake()->randomFloat(2, 5, 2000),
            'currency' => fake()->randomElement(Currency::cases())->value,
            'date' => fake()->dateTimeBetween('-1 year', 'now'),
            'note' => fake()->optional()->sentence(),
        ];
    }
}
