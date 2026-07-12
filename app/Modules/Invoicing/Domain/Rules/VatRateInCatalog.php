<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Domain\Rules;

use App\Modules\Invoicing\Domain\Models\VatRate;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * A numeric item VAT rate must match an entry in the tenant's VAT rate
 * catalog for the given country, valid on the given date. Items don't hold a
 * foreign key to the catalog — this only gates writes, so the rate stays a
 * frozen snapshot even after a catalog entry later expires or is deleted.
 *
 * 0% is always allowed (reverse charge, non-payer, exempt supplies).
 */
final class VatRateInCatalog implements ValidationRule
{
    public function __construct(
        private readonly string $userId,
        private readonly string $country,
        private readonly ?string $onDate = null,
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $rate = (float) $value;

        if ($rate <= 0.0) {
            return;
        }

        $date = $this->resolveDate();
        $country = strtoupper($this->country);

        $exists = VatRate::withoutGlobalScope('user')
            ->where('user_id', $this->userId)
            ->where('country', $country)
            ->get()
            ->contains(fn (VatRate $vatRate): bool => (float) $vatRate->rate === $rate && $vatRate->isValidOn($date));

        if (! $exists) {
            $fail(__('invoicing.vat_rate_not_in_catalog', ['rate' => $value]));
        }
    }

    private function resolveDate(): Carbon
    {
        if ($this->onDate === null || $this->onDate === '') {
            return Carbon::now();
        }

        try {
            return Carbon::parse($this->onDate);
        } catch (Throwable) {
            return Carbon::now();
        }
    }
}
