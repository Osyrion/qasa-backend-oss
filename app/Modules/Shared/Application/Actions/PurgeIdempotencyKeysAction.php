<?php

declare(strict_types=1);

namespace App\Modules\Shared\Application\Actions;

use App\Modules\Shared\Domain\Models\IdempotencyKey;
use App\Modules\Shared\Support\TenantContext;
use Carbon\CarbonImmutable;

final readonly class PurgeIdempotencyKeysAction
{
    private const TTL_HOURS = 24;

    /**
     * One delete per account — see PurgeActivityLogAction for why a single
     * statement across the table stops deleting anything under a policy.
     */
    public function execute(CarbonImmutable $now): int
    {
        $cutoff = $now->subHours(self::TTL_HOURS);
        $deleted = 0;

        TenantContext::forEachAccount(function () use ($cutoff, &$deleted): void {
            $deleted += IdempotencyKey::where('created_at', '<', $cutoff)->delete();
        });

        return $deleted;
    }
}
