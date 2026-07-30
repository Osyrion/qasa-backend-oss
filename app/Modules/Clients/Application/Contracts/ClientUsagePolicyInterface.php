<?php

declare(strict_types=1);

namespace App\Modules\Clients\Application\Contracts;

use App\Modules\Clients\Domain\Models\Client;

interface ClientUsagePolicyInterface
{
    /**
     * Whether the client may be used in mutating operations (editing the
     * client, creating orders/invoices/payments for it). A client that is
     * not usable stays visible but read-only.
     */
    public function isUsable(Client $client): bool;
}
