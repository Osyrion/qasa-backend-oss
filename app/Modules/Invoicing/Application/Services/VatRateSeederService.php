<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Application\Services;

use App\Modules\Auth\Domain\Models\User;
use App\Modules\Invoicing\Domain\Models\VatRate;
use App\Modules\Taxation\Application\Contracts\TaxSystemResolverInterface;

/**
 * Seeds a tenant's VAT rate catalog from TaxSystem::vatRateCatalog() once
 * residency is completed (step 2). Idempotent — safe to re-run (e.g. via
 * the qasa:invoices:backfill-vat-rates command) since it skips codes the
 * account already has.
 */
class VatRateSeederService
{
    public function __construct(
        private readonly TaxSystemResolverInterface $taxSystemResolver,
    ) {}

    public function seedFor(User $user): void
    {
        if ($user->country === null) {
            return;
        }

        $userId = $user->accountOwnerId();
        $country = strtoupper($user->country);
        $taxSystem = $this->taxSystemResolver->forUser($user);

        $rates = $taxSystem->vatRateCatalog();
        $defaultRate = $taxSystem->defaultVatRate();

        $existingCodes = VatRate::withoutGlobalScope('user')
            ->where('user_id', $userId)
            ->pluck('code')
            ->all();

        foreach ($rates as $rate) {
            $code = sprintf('%s-%s', $country, (string) $rate);

            if (in_array($code, $existingCodes, true)) {
                continue;
            }

            VatRate::create([
                'user_id' => $userId,
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
