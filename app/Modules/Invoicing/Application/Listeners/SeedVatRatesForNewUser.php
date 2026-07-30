<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Application\Listeners;

use App\Modules\Auth\Domain\Events\TaxResidencyCompleted;
use App\Modules\Invoicing\Application\Services\VatRateSeederService;

/**
 * Waits for complete-residency (step 2), not UserRegistered — a freshly
 * registered user has no country yet, so there's no catalog to seed.
 */
readonly class SeedVatRatesForNewUser
{
    public function __construct(
        private VatRateSeederService $seeder,
    ) {}

    public function handle(TaxResidencyCompleted $event): void
    {
        $this->seeder->seedFor($event->user);
    }
}
