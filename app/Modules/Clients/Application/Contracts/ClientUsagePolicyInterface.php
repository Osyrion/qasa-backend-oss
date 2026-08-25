<?php

declare(strict_types=1);

namespace App\Modules\Clients\Application\Contracts;

use App\Modules\Clients\Domain\ValueObjects\ClientUsageSubject;

/**
 * Whether a client may still be used, given whatever limit the account is on.
 *
 * The value rather than the model: the implementation that matters is in the
 * premium Saas module, and it needs an id, an account and two booleans —
 * nothing that justifies handing it Clients' aggregate.
 */
interface ClientUsagePolicyInterface
{
    /**
     * Whether the client may be used in mutating operations (editing the
     * client, creating orders/invoices/payments for it). A client that is
     * not usable stays visible but read-only.
     */
    public function isUsable(ClientUsageSubject $client): bool;
}
