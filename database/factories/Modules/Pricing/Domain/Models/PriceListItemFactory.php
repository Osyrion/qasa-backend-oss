<?php

declare(strict_types=1);

namespace Database\Factories\Modules\Pricing\Domain\Models;

use App\Modules\Pricing\Domain\Models\PriceListItem;
use App\Modules\Shared\Enums\ItemUnit;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PriceListItem>
 */
class PriceListItemFactory extends Factory
{
    protected $model = PriceListItem::class;

    public function definition(): array
    {
        return [
            'price_list_id' => PriceListFactory::new(),
            'name' => fake()->words(3, true),
            'description' => fake()->optional()->sentence(),
            'unit' => fake()->randomElement(ItemUnit::cases())->value,
            'unit_price' => fake()->randomFloat(2, 5, 500),
            'vat_rate' => fake()->randomElement([0, 10, 20, 21, 23]),
            'is_active' => true,
            'sort_order' => fake()->numberBetween(0, 20),
        ];
    }
}
