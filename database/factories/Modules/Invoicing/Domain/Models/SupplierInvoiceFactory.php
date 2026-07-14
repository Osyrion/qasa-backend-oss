<?php

declare(strict_types=1);

namespace Database\Factories\Modules\Invoicing\Domain\Models;

use App\Modules\Auth\Domain\Models\User;
use App\Modules\Invoicing\Domain\Enums\SupplierInvoiceStatus;
use App\Modules\Invoicing\Domain\Models\SupplierInvoice;
use App\Modules\Shared\Enums\Currency;
use Database\Factories\Modules\Clients\Domain\Models\ClientFactory;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SupplierInvoice>
 */
class SupplierInvoiceFactory extends Factory
{
    protected $model = SupplierInvoice::class;

    public function definition(): array
    {
        $subtotal = fake()->randomFloat(2, 100, 10000);
        $vatAmount = round($subtotal * fake()->randomElement([0, 0.1, 0.2, 0.21, 0.23]), 2);
        $issuedAt = fake()->dateTimeBetween('-3 months', 'now');

        return [
            'user_id' => User::factory(),
            'client_id' => ClientFactory::new()->vendor(),
            'internal_number' => 'DF-'.now()->format('Y').'-'.fake()->unique()->numberBetween(1, 9999),
            'supplier_invoice_number' => fake()->bothify('INV-####/??'),
            'status' => SupplierInvoiceStatus::Draft->value,
            'issued_at' => $issuedAt,
            'due_at' => (clone $issuedAt)->modify('+14 days'),
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
            'status' => SupplierInvoiceStatus::Draft->value,
        ]);
    }

    public function received(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => SupplierInvoiceStatus::Received->value,
            'received_at' => now()->toDateString(),
        ]);
    }

    public function paid(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => SupplierInvoiceStatus::Paid->value,
            'received_at' => now()->toDateString(),
            'paid_at' => now()->toDateString(),
        ]);
    }
}
