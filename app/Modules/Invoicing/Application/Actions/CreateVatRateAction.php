<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Application\Actions;

use App\Modules\Invoicing\Application\DTOs\VatRateData;
use App\Modules\Invoicing\Domain\Models\VatRate;
use Illuminate\Support\Facades\DB;
use Throwable;

readonly class CreateVatRateAction
{
    /**
     * @throws Throwable
     */
    public function execute(VatRateData $data, string $userId): VatRate
    {
        return DB::transaction(function () use ($data, $userId): VatRate {
            if ($data->is_default) {
                $this->unsetCurrentDefault($userId, $data->country);
            }

            return VatRate::create([
                'user_id' => $userId,
                'code' => $data->code,
                'country' => $data->country,
                'rate' => $data->rate,
                'label' => $data->label,
                'is_default' => $data->is_default,
                'valid_from' => $data->valid_from,
                'valid_to' => $data->valid_to,
            ]);
        });
    }

    private function unsetCurrentDefault(string $userId, string $country): void
    {
        VatRate::withoutGlobalScope('user')
            ->where('user_id', $userId)
            ->where('country', $country)
            ->where('is_default', true)
            ->update(['is_default' => false]);
    }
}
