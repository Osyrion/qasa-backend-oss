<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Application\Services;

use App\Modules\Auth\Domain\Models\User;
use App\Modules\Invoicing\Domain\Models\VatRate;

/**
 * Seeds a tenant's VAT rate catalog from config('taxation.*') on
 * registration. Idempotent — safe to re-run (e.g. via the
 * invoicing:backfill-vat-rates command) since it skips codes the user
 * already has.
 */
class VatRateSeederService
{
    public function seedFor(User $user): void
    {
        $country = strtoupper($user->country !== '' ? $user->country : 'SK');

        /** @var list<int|float> $rates */
        $rates = config("taxation.{$country}.vat_rates", config('taxation.SK.vat_rates', []));
        $defaultRate = config("taxation.{$country}.default_vat_rate", config('taxation.SK.default_vat_rate'));

        $existingCodes = VatRate::withoutGlobalScope('user')
            ->where('user_id', $user->id)
            ->pluck('code')
            ->all();

        foreach ($rates as $rate) {
            $code = sprintf('%s-%s', $country, (string) $rate);

            if (in_array($code, $existingCodes, true)) {
                continue;
            }

            VatRate::create([
                'user_id' => $user->id,
                'code' => $code,
                'country' => $country,
                'rate' => $rate,
                'label' => null,
                'is_default' => (float) $rate === (float) $defaultRate,
                'valid_from' => null,
                'valid_to' => null,
            ]);
        }
    }
}
