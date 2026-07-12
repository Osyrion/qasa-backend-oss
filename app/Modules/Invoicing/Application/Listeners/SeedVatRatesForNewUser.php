<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Application\Listeners;

use App\Modules\Auth\Domain\Events\UserRegistered;
use App\Modules\Invoicing\Application\Services\VatRateSeederService;

readonly class SeedVatRatesForNewUser
{
    public function __construct(
        private VatRateSeederService $seeder,
    ) {}

    public function handle(UserRegistered $event): void
    {
        $this->seeder->seedFor($event->user);
    }
}
