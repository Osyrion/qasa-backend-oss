<?php

declare(strict_types=1);

namespace Database\Factories\Modules\Invoicing\Domain\Models;

use App\Modules\Invoicing\Domain\Models\QuoteItem;
use App\Modules\Shared\Enums\ItemUnit;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<QuoteItem>
 */
class QuoteItemFactory extends Factory
{
    protected $model = QuoteItem::class;

    public function definition(): array
    {
        $quantity = fake()->randomFloat(3, 1, 20);
        $unitPrice = fake()->randomFloat(2, 10, 500);
        $vatRate = fake()->randomElement([0, 10, 20, 21, 23]);
        $totalExclVat = round($quantity * $unitPrice, 2);
        $vatAmount = round($totalExclVat * $vatRate / 100, 2);

        return [
            'quote_id' => QuoteFactory::new(),
            'description' => fake()->sentence(4),
            'quantity' => $quantity,
            'unit' => fake()->randomElement(ItemUnit::cases())->value,
            'unit_price' => $unitPrice,
            'vat_rate' => $vatRate,
            'vat_amount' => $vatAmount,
            'total_excl_vat' => $totalExclVat,
            'total_incl_vat' => $totalExclVat + $vatAmount,
            'sort_order' => fake()->numberBetween(0, 100),
        ];
    }
}
