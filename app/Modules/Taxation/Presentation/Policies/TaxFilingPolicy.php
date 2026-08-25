<?php

declare(strict_types=1);

namespace App\Modules\Taxation\Presentation\Policies;

use App\Modules\Shared\Domain\Contracts\Actor;
use App\Modules\Shared\Policies\InteractsWithAccount;
use App\Modules\Taxation\Domain\Models\TaxFiling;

/**
 * No delete() — the archive is immutable and permanent by design (see
 * TaxFiling::booted()); there is no "undo generating a filing."
 */
class TaxFilingPolicy
{
    use InteractsWithAccount;

    public function viewAny(Actor $user): bool
    {
        return $user->can('taxation.view');
    }

    public function view(Actor $user, TaxFiling $filing): bool
    {
        return $this->sameAccount($user, $filing->user_id) && $user->can('taxation.view');
    }

    public function create(Actor $user): bool
    {
        return $user->can('taxation.manage');
    }

    public function update(Actor $user, TaxFiling $filing): bool
    {
        return $this->sameAccount($user, $filing->user_id) && $user->can('taxation.manage');
    }
}
