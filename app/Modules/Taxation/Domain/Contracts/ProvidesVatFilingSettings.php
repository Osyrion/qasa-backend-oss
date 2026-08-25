<?php

declare(strict_types=1);

namespace App\Modules\Taxation\Domain\Contracts;

use App\Modules\Taxation\Domain\Enums\VatFilingFrequency;

/**
 * How often the account files a VAT return, and whether it wants reminding.
 *
 * Lives in Taxation rather than Shared because the frequency is a Taxation
 * enum and the deadline arithmetic that consumes it is Taxation's — the
 * columns happen to sit on `users`, which is a storage detail, not ownership.
 * The scheduled reminder command lives in Automation and reads it from here.
 */
interface ProvidesVatFilingSettings
{
    /** Null until the account sets it in its profile. */
    public function vatFilingFrequency(): ?VatFilingFrequency;

    public function taxFilingRemindersEnabled(): bool;
}
