<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Application\Actions;

use App\Modules\Pricing\Application\DTOs\PriceListData;
use App\Modules\Pricing\Domain\Models\PriceList;
use Illuminate\Support\Facades\DB;
use Throwable;

class CreatePriceListAction
{
    /**
     * @throws Throwable
     */
    public function execute(PriceListData $data, string $userId): PriceList
    {
        return DB::transaction(function () use ($data, $userId): PriceList {
            if ($data->is_default) {
                PriceList::withoutGlobalScope('user')
                    ->where('user_id', $userId)
                    ->update(['is_default' => false]);
            }

            return PriceList::create([
                'user_id' => $userId,
                'name' => $data->name,
                'description' => $data->description,
                'currency' => $data->currency?->value,
                'country' => $data->country,
                'is_default' => $data->is_default,
            ]);
        });
    }
}
