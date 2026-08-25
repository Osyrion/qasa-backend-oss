<?php

declare(strict_types=1);

namespace App\Modules\Clients\Application\Contracts;

use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * The per-client opt-out from Automation's "send the invoice when it is
 * issued" rule.
 *
 * `clients.auto_send_invoices` is a premium column on a core table — the
 * meaning belongs to Automation, the row belongs to us, and this is the seam
 * between the two. Same arrangement as {@see ClientBankAccountLearner} for
 * Banking.
 */
interface ClientAutoSendPreference
{
    /**
     * @return bool the stored value after the write
     *
     * @throws ModelNotFoundException when the current account does not own the client
     */
    public function set(string $clientId, bool $enabled): bool;
}
