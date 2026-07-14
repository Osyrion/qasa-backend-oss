<?php

declare(strict_types=1);

namespace Database\Factories\Modules\Invoicing\Domain\Models;

use App\Modules\Auth\Domain\Models\User;
use App\Modules\Invoicing\Domain\Enums\ExpenseCategory;
use App\Modules\Invoicing\Domain\Models\Expense;
use App\Modules\Shared\Enums\Currency;
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
