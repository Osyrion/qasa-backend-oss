<?php

declare(strict_types=1);

namespace App\Modules\Clients\Application\Contracts;

/**
 * Remember which account a client pays from.
 *
 * Banking learns this from a confirmed statement match and used to write it
 * by reaching through `$invoice->client` into the `Client` aggregate — a
 * *write* to somebody else's model, which no read-model value object fixes.
 * This is the write, named after what it means rather than after the column.
 *
 * **Edition note:** the column behind it (`clients.bank_iban`) is added by a
 * Banking migration, so it does not exist in the generated OSS core — where
 * nothing calls this either, Banking being the only caller. Same arrangement
 * as the `client.anonymize` tag in ClientsServiceProvider.
 */
interface ClientBankAccountLearner
{
    /**
     * Idempotent: an $iban the client already carries is not re-written, so
     * repeatedly confirming matches from the same account does not churn
     * `updated_at`. An id the current account does not own is a no-op — the
     * caller resolved it from a document of its own, and a client that is
     * invisible here is not one to teach anything about.
     */
    public function remember(string $clientId, string $iban): void;
}
