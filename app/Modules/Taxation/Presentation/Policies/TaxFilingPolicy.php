<?php

declare(strict_types=1);

namespace App\Modules\Taxation\Presentation\Policies;

use App\Modules\Auth\Domain\Models\User;
use App\Modules\Shared\Policies\InteractsWithAccount;
use App\Modules\Taxation\Domain\Models\TaxFiling;

/**
 * No delete() — the archive is immutable and permanent by design (see
 * TaxFiling::booted()); there is no "undo generating a filing."
 */
class TaxFilingPolicy
{
    use InteractsWithAccount;

    public function viewAny(User $user): bool
    {
        return $user->can('taxation.view');
    }

    public function view(User $user, TaxFiling $filing): bool
    {
        return $this->sameAccount($user, $filing->user_id) && $user->can('taxation.view');
    }

    public function create(User $user): bool
    {
        return $user->can('taxation.manage');
    }

    public function update(User $user, TaxFiling $filing): bool
    {
        return $this->sameAccount($user, $filing->user_id) && $user->can('taxation.manage');
    }
}
