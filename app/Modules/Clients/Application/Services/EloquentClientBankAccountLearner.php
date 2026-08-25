<?php

declare(strict_types=1);

namespace App\Modules\Clients\Application\Services;

use App\Modules\Clients\Application\Contracts\ClientBankAccountLearner;
use App\Modules\Clients\Domain\Models\Client;

/**
 * The account scope is left on deliberately, the same way
 * EloquentClientDirectory leaves it on: a client the caller's account cannot
 * see is simply not there, and the write is skipped rather than reaching
 * across the tenant boundary.
 */
final readonly class EloquentClientBankAccountLearner implements ClientBankAccountLearner
{
    public function remember(string $clientId, string $iban): void
    {
        $client = Client::query()->find($clientId);

        // The second half is not what keeps updated_at still — Eloquent's own
        // dirty check does that. It keeps save() from being called at all, so
        // an unchanged account fires no model events either.
        if (! $client instanceof Client || $client->bank_iban === $iban) {
            return;
        }

        // Unconditional overwrite, not "set once" — the latest confirmation
        // is the most likely still-current account; this project does not
        // model a client paying from several accounts.
        $client->forceFill(['bank_iban' => $iban])->save();
    }
}
