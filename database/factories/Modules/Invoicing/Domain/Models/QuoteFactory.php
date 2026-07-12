<?php

declare(strict_types=1);

namespace Database\Factories\Modules\Invoicing\Domain\Models;

use App\Modules\Auth\Domain\Models\User;
use App\Modules\Invoicing\Domain\Enums\QuoteStatus;
use App\Modules\Invoicing\Domain\Models\Quote;
use App\Modules\Shared\Enums\Currency;
use Database\Factories\Modules\Clients\Domain\Models\ClientFactory;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Quote>
 */
class QuoteFactory extends Factory
{
    protected $model = Quote::class;

    public function definition(): array
    {
        $subtotal = fake()->randomFloat(2, 100, 10000);
        $vatAmount = round($subtotal * fake()->randomElement([0, 0.1, 0.2, 0.21, 0.23]), 2);
        $issuedAt = fake()->dateTimeBetween('-3 months', 'now');

        return [
            'user_id' => User::factory(),
            'client_id' => ClientFactory::new(),
            'quote_number' => 'CP-'.now()->format('Y').'-'.fake()->unique()->numberBetween(1, 9999),
            'status' => QuoteStatus::Draft->value,
            'issued_at' => $issuedAt,
            'valid_until' => (clone $issuedAt)->modify('+30 days'),
            'currency' => fake()->randomElement(Currency::cases())->value,
            'subtotal' => $subtotal,
            'vat_amount' => $vatAmount,
            'total' => $subtotal + $vatAmount,
            'note' => fake()->optional()->sentence(),
        ];
    }

    public function draft(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => QuoteStatus::Draft->value,
        ]);
    }

    public function sent(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => QuoteStatus::Sent->value,
        ]);
    }

    public function accepted(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => QuoteStatus::Accepted->value,
            'accepted_at' => now(),
        ]);
    }
}
