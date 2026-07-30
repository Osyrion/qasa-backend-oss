<?php

declare(strict_types=1);

namespace App\Modules\Shared\Application\Actions;

use App\Modules\Shared\Domain\Models\ActivityLog;
use App\Modules\Shared\Support\TenantContext;
use Carbon\CarbonImmutable;

final readonly class PurgeActivityLogAction
{
    /**
     * One delete per account rather than one across the table.
     *
     * The single statement this replaces still reads correctly, and under a
     * row-level policy it deletes nothing: a scheduled command is bound to no
     * account, so the policy matches no rows and the purge silently stops
     * working. Walking the accounts is the only way to reach them all without
     * handing the runtime role a way around the policy.
     */
    public function execute(CarbonImmutable $today): int
    {
        $retentionDays = (int) config('activity.retention_days', 730);
        $cutoff = $today->subDays($retentionDays);

        $deleted = 0;

        TenantContext::forEachAccount(function () use ($cutoff, &$deleted): void {
            $deleted += ActivityLog::withoutGlobalScope('user')->where('created_at', '<', $cutoff)->delete();
        });

        return $deleted;
    }
}
