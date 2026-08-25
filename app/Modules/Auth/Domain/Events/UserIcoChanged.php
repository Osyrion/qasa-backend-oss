<?php

declare(strict_types=1);

namespace App\Modules\Auth\Domain\Events;

use App\Modules\Shared\Domain\Contracts\Account;
use App\Modules\Shared\Domain\Contracts\ProvidesSupplierProfile;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired on every IČO change (including the first one, set at
 * complete-residency) — anti-fraud audit trail, see
 * docs/plans/TAX_RESIDENCY_PHASE_1_REGISTRATION_RESIDENCY.md §1.6.
 */
class UserIcoChanged
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly Account&Model&ProvidesSupplierProfile $user,
        public readonly ?string $oldIco,
        public readonly ?string $newIco,
    ) {}
}
