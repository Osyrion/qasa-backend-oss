<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Application\Services;

use App\Modules\Invoicing\Application\Contracts\AccountInvoicingState;
use App\Modules\Invoicing\Domain\Models\BankAccount;
use App\Modules\Invoicing\Domain\Models\Invoice;
use App\Modules\Invoicing\Domain\Models\Quote;
use App\Modules\Invoicing\Domain\Models\SupplierInvoice;

/**
 * The scope comes off deliberately: both callers run for an explicit account
 * id — onboarding on the authenticated one, the profile action on the owner
 * behind a possibly-member caller — and the global scope would key off
 * whoever happens to be logged in.
 */
final class EloquentAccountInvoicingState implements AccountInvoicingState
{
    public function hasAnyDocument(string $ownerId): bool
    {
        return Invoice::withoutGlobalScope('user')->where('user_id', $ownerId)->exists()
            || Quote::withoutGlobalScope('user')->where('user_id', $ownerId)->exists()
            || SupplierInvoice::withoutGlobalScope('user')->where('user_id', $ownerId)->exists();
    }

    public function hasInvoice(string $ownerId): bool
    {
        return Invoice::withoutGlobalScope('user')->where('user_id', $ownerId)->exists();
    }

    public function hasBankAccount(string $ownerId): bool
    {
        return BankAccount::withoutGlobalScope('user')->where('user_id', $ownerId)->exists();
    }
}
