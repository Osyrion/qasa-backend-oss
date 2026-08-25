<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Presentation\Policies;

use App\Modules\Invoicing\Domain\Models\ExchangeRate;
use App\Modules\Shared\Domain\Contracts\Actor;
use App\Modules\Shared\Policies\InteractsWithAccount;

/**
 * Exchange rates had no policy at all, so every authenticated caller —
 * including a read-only Viewer and an API token scoped to something else
 * entirely — could add and delete the account's manual rates. The rate is
 * what converts a foreign-currency document into the reporting currency, so
 * this is the money layer, gated by `invoices.*` like the rest of it.
 *
 * The table mixes shared system rows (user_id IS NULL, public reference data
 * fetched by whichever account needs a given day first) with per-account
 * overrides, and has no HasUserScope for that reason — the ownOrShared RLS
 * policy is what keeps another account's override invisible. delete() lets a
 * system row through so the controller can answer with its own
 * "system rates are not deletable" message rather than a bare 403; what
 * this stops is the *ability* to reach either kind.
 */
class ExchangeRatePolicy
{
    use InteractsWithAccount;

    public function viewAny(Actor $user): bool
    {
        return $user->can('invoices.view');
    }

    public function create(Actor $user): bool
    {
        return $user->can('invoices.manage');
    }

    public function delete(Actor $user, ExchangeRate $rate): bool
    {
        if (! $user->can('invoices.manage')) {
            return false;
        }

        return $rate->isSystemRate() || $this->sameAccount($user, $rate->user_id);
    }
}
