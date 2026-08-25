<?php

declare(strict_types=1);

namespace App\Modules\Auth\Domain\Events;

use App\Modules\Shared\Domain\Contracts\Account;
use App\Modules\Shared\Domain\Contracts\ProvidesSupplierProfile;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired once, when complete-residency (step 2) succeeds. Distinct from
 * UserRegistered — VAT rate seeding and any other residency-dependent setup
 * must wait for this, since a freshly registered user has no country yet.
 */
class TaxResidencyCompleted
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly Account&Model&ProvidesSupplierProfile $user,
    ) {}
}
