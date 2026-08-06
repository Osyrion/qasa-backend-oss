<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Presentation\Policies;

use App\Modules\Auth\Domain\Models\User;
use App\Modules\Invoicing\Domain\Models\CashDocument;
use App\Modules\Shared\Policies\InteractsWithAccount;

/**
 * No update() and no delete(): cash documents are append-only, so there is
 * no action to authorize. Reversal is a create.
 */
class CashDocumentPolicy
{
    use InteractsWithAccount;

    public function viewAny(User $user): bool
    {
        return $user->can('invoices.view');
    }

    public function view(User $user, CashDocument $document): bool
    {
        return $this->sameAccount($user, $document->user_id) && $user->can('invoices.view');
    }

    public function create(User $user): bool
    {
        return $user->can('invoices.manage');
    }
}
