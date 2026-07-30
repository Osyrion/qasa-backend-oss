<?php

declare(strict_types=1);

namespace App\Modules\Clients\Application\Services;

use App\Modules\Clients\Application\Contracts\ClientUsagePolicyInterface;
use App\Modules\Clients\Domain\Models\Client;

/**
 * Core default: no client locking. The SaaS edition rebinds
 * a free-tier policy that locks clients above the plan limits.
 */
final class AlwaysUsableClientPolicy implements ClientUsagePolicyInterface
{
    public function isUsable(Client $client): bool
    {
        return true;
    }
}
