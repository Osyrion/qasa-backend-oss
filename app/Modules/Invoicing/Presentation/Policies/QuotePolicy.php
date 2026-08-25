<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Presentation\Policies;

use App\Modules\Invoicing\Domain\Models\Quote;
use App\Modules\Shared\Domain\Contracts\Actor;
use App\Modules\Shared\Policies\InteractsWithAccount;

class QuotePolicy
{
    use InteractsWithAccount;

    public function viewAny(Actor $user): bool
    {
        return $user->can('invoices.view');
    }

    public function view(Actor $user, Quote $quote): bool
    {
        return $this->sameAccount($user, $quote->user_id) && $user->can('invoices.view');
    }

    public function create(Actor $user): bool
    {
        return $user->can('invoices.manage');
    }

    public function update(Actor $user, Quote $quote): bool
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
    public function updateStatus(Actor $user, Quote $quote): bool
    {
        return $this->sameAccount($user, $quote->user_id) && $user->can('invoices.manage');
    }

    public function email(Actor $user, Quote $quote): bool
    {
        return $this->sameAccount($user, $quote->user_id) && $user->can('invoices.manage');
    }

    public function publicLink(Actor $user, Quote $quote): bool
    {
        return $this->sameAccount($user, $quote->user_id) && $user->can('invoices.manage');
    }

    public function convert(Actor $user, Quote $quote): bool
    {
        return $this->sameAccount($user, $quote->user_id) && $user->can('invoices.manage');
    }

    public function delete(Actor $user, Quote $quote): bool
    {
        return $this->sameAccount($user, $quote->user_id)
            && $user->can('invoices.manage')
            && $quote->isDraft();
    }
}
