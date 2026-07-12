<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Application\Actions;

use App\Modules\Invoicing\Application\DTOs\VatRateData;
use App\Modules\Invoicing\Domain\Models\VatRate;
use Illuminate\Support\Facades\DB;
use Throwable;

readonly class UpdateVatRateAction
{
    /**
     * @throws Throwable
     */
    public function execute(VatRate $vatRate, VatRateData $data): VatRate
    {
        return DB::transaction(function () use ($vatRate, $data): VatRate {
            if ($data->is_default) {
                VatRate::withoutGlobalScope('user')
                    ->where('user_id', $vatRate->user_id)
                    ->where('country', $data->country)
                    ->where('is_default', true)
                    ->whereKeyNot($vatRate->id)
                    ->update(['is_default' => false]);
            }

            $vatRate->update([
                'code' => $data->code,
                'country' => $data->country,
                'rate' => $data->rate,
                'label' => $data->label,
                'is_default' => $data->is_default,
                'valid_from' => $data->valid_from,
                'valid_to' => $data->valid_to,
            ]);

            return $vatRate;
        });
    }
}
