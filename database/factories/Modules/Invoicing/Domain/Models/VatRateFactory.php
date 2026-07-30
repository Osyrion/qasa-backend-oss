<?php

declare(strict_types=1);

namespace Database\Factories\Modules\Invoicing\Domain\Models;

use App\Modules\Invoicing\Domain\Models\VatRate;
use Database\Factories\BoundAccount;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<VatRate>
 */
class VatRateFactory extends Factory
{
    protected $model = VatRate::class;

    public function definition(): array
    {
        $country = 'SK';
        $rate = fake()->randomElement([0, 5, 10, 23]);

        return [
            'user_id' => fn (): string => BoundAccount::id(),
            'code' => "{$country}-{$rate}",
            'country' => $country,
            'rate' => $rate,
            'label' => null,
            'is_default' => false,
            'valid_from' => null,
            'valid_to' => null,
        ];
    }

    public function default(): static
    {
        return $this->state(fn (array $attributes): array => [
            'is_default' => true,
        ]);
    }
}
