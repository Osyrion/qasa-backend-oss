<?php

declare(strict_types=1);

namespace Database\Factories\Modules\Invoicing\Domain\Models;

use App\Modules\Invoicing\Domain\Enums\CashDocumentType;
use App\Modules\Invoicing\Domain\Models\CashDocument;
use App\Modules\Shared\Enums\Currency;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CashDocument>
 */
class CashDocumentFactory extends Factory
{
    protected $model = CashDocument::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $type = fake()->randomElement(CashDocumentType::cases());

        return [
            'type' => $type->value,
            'number' => $type->numberPrefix().'-'.now()->year.'-'.fake()->unique()->numberBetween(1, 999),
            'issued_at' => now()->toDateString(),
            'amount' => fake()->randomFloat(2, 5, 500),
            'currency' => Currency::EUR->value,
            'description' => fake()->sentence(3),
        ];
    }

    public function income(): self
    {
        return $this->state(fn (): array => ['type' => CashDocumentType::Income->value]);
    }

    public function expense(): self
    {
        return $this->state(fn (): array => ['type' => CashDocumentType::Expense->value]);
    }
}
