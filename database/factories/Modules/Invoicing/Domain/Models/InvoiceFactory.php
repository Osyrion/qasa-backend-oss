<?php

declare(strict_types=1);

namespace Database\Factories\Modules\Invoicing\Domain\Models;

use App\Modules\Invoicing\Domain\Enums\InvoiceStatus;
use App\Modules\Invoicing\Domain\Enums\InvoiceType;
use App\Modules\Invoicing\Domain\Models\Invoice;
use App\Modules\Shared\Enums\Currency;
use Database\Factories\BoundAccount;
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
            'user_id' => fn (): string => BoundAccount::id(),
            'client_id' => fn (array $attributes) => ClientFactory::new()->create(['user_id' => $attributes['user_id']]),
            // Declared before invoice_number so the closure below sees it
            // expanded — a state or a create([...]) override lands in this
            // same slot, keeping the number in step with the type.
            'type' => InvoiceType::Invoice->value,
            // Each type has its own series (InvoiceType::numberMask()), so a
            // proforma numbered FA- is a document the application cannot
            // produce. 'FA' is the users.invoice_prefix column default, which
            // is what the generator would read for a plain invoice; a test
            // that changes that prefix passes its own number anyway.
            'invoice_number' => fn (array $attributes): string => self::type($attributes['type'])->numberPrefix('FA')
                .'-'.now()->format('Y').'-'.fake()->unique()->numberBetween(1, 9999),
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

    /**
     * Callers write both `'type' => InvoiceType::Proforma` and
     * `'type' => 'proforma'`; the number closure has to cope with either.
     */
    private static function type(InvoiceType|string $type): InvoiceType
    {
        return $type instanceof InvoiceType ? $type : InvoiceType::from($type);
    }

    public function proforma(): static
    {
        return $this->state(fn (array $attributes): array => [
            'type' => InvoiceType::Proforma->value,
        ]);
    }

    public function creditNote(): static
    {
        return $this->state(fn (array $attributes): array => [
            'type' => InvoiceType::CreditNote->value,
        ]);
    }

    public function storno(): static
    {
        return $this->state(fn (array $attributes): array => [
            'type' => InvoiceType::Storno->value,
        ]);
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
