<?php

declare(strict_types=1);

namespace Database\Factories\Modules\Invoicing\Domain\Models;

use App\Modules\Auth\Domain\Models\User;
use App\Modules\Invoicing\Domain\Enums\InvoiceStatus;
use App\Modules\Invoicing\Domain\Enums\InvoiceType;
use App\Modules\Invoicing\Domain\Models\Invoice;
use App\Modules\Shared\Enums\Currency;
use Database\Factories\Modules\Clients\Domain\Models\ClientFactory;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Invoice>
 */
class InvoiceFactory extends Factory
{
    protected $model = Invoice::class;

    public function definition(): array
    {
        $subtotal = fake()->randomFloat(2, 100, 10000);
        $vatAmount = round($subtotal * fake()->randomElement([0, 0.1, 0.2, 0.21, 0.23]), 2);
        $issuedAt = fake()->dateTimeBetween('-3 months', 'now');

        return [
            'user_id' => User::factory(),
            'client_id' => ClientFactory::new(),
            'invoice_number' => 'FA-'.now()->format('Y').'-'.fake()->unique()->numberBetween(1, 9999),
            'type' => InvoiceType::Invoice->value,
            'status' => fake()->randomElement(InvoiceStatus::cases())->value,
            'issued_at' => $issuedAt,
            'due_at' => (clone $issuedAt)->modify('+14 days'),
            'currency' => fake()->randomElement(Currency::cases())->value,
            'exchange_rate_snapshot' => fake()->optional()->randomFloat(6, 0.5, 30),
            'subtotal' => $subtotal,
            'vat_amount' => $vatAmount,
            'total' => $subtotal + $vatAmount,
            'note' => fake()->optional()->sentence(),
        ];
    }

    public function draft(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => InvoiceStatus::Draft->value,
        ]);
    }

    public function sent(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => InvoiceStatus::Sent->value,
        ]);
    }

    public function overdue(): static
    {
        $issuedAt = fake()->dateTimeBetween('-2 months', '-1 month');

        return $this->state(fn (array $attributes): array => [
            'status' => InvoiceStatus::Sent->value,
            'issued_at' => $issuedAt,
            'due_at' => (clone $issuedAt)->modify('+14 days'),
        ]);
    }
}
