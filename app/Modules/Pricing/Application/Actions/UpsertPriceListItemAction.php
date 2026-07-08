<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Application\Actions;

use App\Modules\Pricing\Application\DTOs\PriceListItemData;
use App\Modules\Pricing\Domain\Models\PriceList;
use App\Modules\Pricing\Domain\Models\PriceListItem;
use App\Modules\Shared\Enums\ItemUnit;

class UpsertPriceListItemAction
{
    public function execute(PriceList $priceList, PriceListItemData $data, ?PriceListItem $existing = null): PriceListItem
    {
        $unit = ItemUnit::tryFrom($data->unit)->value ?? $data->unit;

        $attributes = [
            'name' => $data->name,
            'description' => $data->description,
            'unit' => $unit,
            'unit_price' => $data->unit_price,
            'vat_rate' => $data->vat_rate,
            'is_active' => $data->is_active,
            'sort_order' => $data->sort_order,
        ];

        if ($existing !== null) {
            $existing->update($attributes);

            return $existing->fresh() ?? $existing;
        }

        /** @var PriceListItem $item */
        $item = $priceList->items()->create($attributes);

        return $item;
    }
}
