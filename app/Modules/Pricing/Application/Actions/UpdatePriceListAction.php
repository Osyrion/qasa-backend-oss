<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Application\Actions;

use App\Modules\Pricing\Application\DTOs\PriceListData;
use App\Modules\Pricing\Domain\Models\PriceList;
use Illuminate\Support\Facades\DB;
use Throwable;

class UpdatePriceListAction
{
    /**
     * @throws Throwable
     */
    public function execute(PriceList $priceList, PriceListData $data): PriceList
    {
        return DB::transaction(function () use ($priceList, $data): PriceList {
            if ($data->is_default && ! $priceList->is_default) {
                PriceList::withoutGlobalScope('user')
                    ->where('user_id', $priceList->user_id)
                    ->whereKeyNot($priceList->id)
                    ->update(['is_default' => false]);
            }

            $priceList->update([
                'name' => $data->name,
                'description' => $data->description,
                'currency' => $data->currency?->value,
                'country' => $data->country,
                'is_default' => $data->is_default,
            ]);

            return $priceList->fresh() ?? $priceList;
        });
    }
}
