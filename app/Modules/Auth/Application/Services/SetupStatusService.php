<?php

declare(strict_types=1);

namespace App\Modules\Auth\Application\Services;

use App\Modules\Auth\Domain\Models\User;
use App\Modules\Clients\Domain\Models\Client;
use App\Modules\Invoicing\Domain\Models\BankAccount;
use App\Modules\Invoicing\Domain\Models\Invoice;

/**
 * Onboarding checklist for a fresh account — lets the frontend build a
 * setup wizard without hand-rolling the same "is this account ready to
 * invoice?" checks. Reads live account state on every call; seven cheap
 * exists()/null checks per request need no caching, and `completed` must
 * flip the moment the underlying action happens, not after a TTL.
 */
class SetupStatusService
{
    /**
     * @return array{items: array<int, array{key: string, done: bool, optional: bool}>, completed: bool}
     */
    public function getStatus(User $user): array
    {
        $owner = $user->accountOwner();
        $ownerId = $owner->id;

        $items = [
            ['key' => 'billing_identity', 'done' => $this->hasBillingIdentity($owner), 'optional' => false],
            ['key' => 'vat_status', 'done' => $owner->vat_status_confirmed_at !== null, 'optional' => false],
            ['key' => 'bank_account', 'done' => $this->hasBankAccount($ownerId), 'optional' => false],
            ['key' => 'invoice_numbering', 'done' => $this->hasInvoiceNumbering($owner), 'optional' => true],
            ['key' => 'logo', 'done' => $owner->logo_path !== null, 'optional' => true],
            ['key' => 'first_client', 'done' => $this->hasClient($ownerId), 'optional' => false],
            ['key' => 'first_invoice', 'done' => $this->hasInvoice($ownerId), 'optional' => true],
        ];

        $completed = collect($items)->filter(fn (array $item): bool => ! $item['optional'])
            ->every(fn (array $item): bool => $item['done']);

        return ['items' => $items, 'completed' => $completed];
    }

    private function hasBillingIdentity(User $owner): bool
    {
        return $owner->ico !== null && $owner->ico !== ''
            && $owner->address !== null && $owner->address !== ''
            && $owner->city !== null && $owner->city !== ''
            && $owner->country !== '';
    }

    private function hasInvoiceNumbering(User $owner): bool
    {
        return ($owner->invoice_number_mask !== null && $owner->invoice_number_mask !== '')
            || $owner->invoice_prefix !== '';
    }

    private function hasBankAccount(string $ownerId): bool
    {
        return BankAccount::withoutGlobalScope('user')->where('user_id', $ownerId)->exists();
    }

    private function hasClient(string $ownerId): bool
    {
        return Client::withoutGlobalScope('user')->where('user_id', $ownerId)->exists();
    }

    private function hasInvoice(string $ownerId): bool
    {
        return Invoice::withoutGlobalScope('user')->where('user_id', $ownerId)->exists();
    }
}
