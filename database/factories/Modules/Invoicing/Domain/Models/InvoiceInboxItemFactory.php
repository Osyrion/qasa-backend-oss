<?php

declare(strict_types=1);

namespace Database\Factories\Modules\Invoicing\Domain\Models;

use App\Modules\Auth\Domain\Models\User;
use App\Modules\Invoicing\Domain\Enums\InvoiceInboxStatus;
use App\Modules\Invoicing\Domain\Models\InvoiceInboxItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InvoiceInboxItem>
 */
class InvoiceInboxItemFactory extends Factory
{
    protected $model = InvoiceInboxItem::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'status' => InvoiceInboxStatus::Pending->value,
            'disk' => 'local',
            'path' => 'supplier-invoices/inbox/'.fake()->uuid().'.pdf',
            'original_filename' => fake()->word().'.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => fake()->numberBetween(10_000, 500_000),
            'file_hash' => hash('sha256', fake()->unique()->uuid()),
            'ocr_text' => 'Faktúra číslo: INV-001',
            'ocr_engine' => 'pdfparser',
            'suggestions' => ['supplier_invoice_number' => 'INV-001'],
            'scanned_at' => now(),
        ];
    }

    public function pending(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => InvoiceInboxStatus::Pending->value,
        ]);
    }

    public function imported(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => InvoiceInboxStatus::Imported->value,
        ]);
    }

    public function ignored(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => InvoiceInboxStatus::Ignored->value,
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => InvoiceInboxStatus::Failed->value,
            'ocr_text' => '',
            'suggestions' => null,
            'error' => 'Could not extract text from the document.',
        ]);
    }
}
