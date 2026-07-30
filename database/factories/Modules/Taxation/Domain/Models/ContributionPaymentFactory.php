<?php

declare(strict_types=1);

namespace Database\Factories\Modules\Taxation\Domain\Models;

use App\Modules\Shared\Enums\Currency;
use App\Modules\Taxation\Domain\Enums\ContributionType;
use App\Modules\Taxation\Domain\Models\ContributionPayment;
use Database\Factories\BoundAccount;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ContributionPayment>
 */
class ContributionPaymentFactory extends Factory
{
    protected $model = ContributionPayment::class;

    public function definition(): array
    {
        $paidAt = fake()->dateTimeBetween('-1 year', 'now');

        return [
            'user_id' => fn (): string => BoundAccount::id(),
            'type' => fake()->randomElement(ContributionType::cases())->value,
            'period_year' => (int) $paidAt->format('Y'),
            'period_month' => fake()->numberBetween(1, 12),
            'amount' => fake()->randomFloat(2, 50, 900),
            'currency' => fake()->randomElement(Currency::cases())->value,
            'paid_at' => $paidAt,
            'note' => fake()->optional()->sentence(),
        ];
    }
}
