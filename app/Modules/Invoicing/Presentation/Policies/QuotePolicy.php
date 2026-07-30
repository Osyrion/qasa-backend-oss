<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Presentation\Policies;

use App\Modules\Auth\Domain\Models\User;
use App\Modules\Invoicing\Domain\Models\Quote;
use App\Modules\Shared\Policies\InteractsWithAccount;

class QuotePolicy
{
    use InteractsWithAccount;

    public function viewAny(User $user): bool
    {
        return $user->can('invoices.view');
    }

    public function view(User $user, Quote $quote): bool
    {
        return $this->sameAccount($user, $quote->user_id) && $user->can('invoices.view');
    }

    public function create(User $user): bool
    {
        return $user->can('invoices.manage');
    }

    public function update(User $user, Quote $quote): bool
    {
        return $this->sameAccount($user, $quote->user_id)
            && $user->can('invoices.manage')
            && $quote->isEditable();
    }

    /**
     * Status transitions, emailing and public-link management must work on
     * non-draft quotes too, so these deliberately skip the isEditable()
     * check in update().
     */
    public function updateStatus(User $user, Quote $quote): bool
    {
        return $this->sameAccount($user, $quote->user_id) && $user->can('invoices.manage');
    }

    public function email(User $user, Quote $quote): bool
    {
        return $this->sameAccount($user, $quote->user_id) && $user->can('invoices.manage');
    }

    public function publicLink(User $user, Quote $quote): bool
    {
        return $this->sameAccount($user, $quote->user_id) && $user->can('invoices.manage');
    }

    public function convert(User $user, Quote $quote): bool
    {
        return $this->sameAccount($user, $quote->user_id) && $user->can('invoices.manage');
    }

    public function delete(User $user, Quote $quote): bool
    {
        return $this->sameAccount($user, $quote->user_id)
            && $user->can('invoices.manage')
            && $quote->isDraft();
    }
}
