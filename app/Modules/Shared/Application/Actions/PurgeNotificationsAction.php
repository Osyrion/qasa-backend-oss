<?php

declare(strict_types=1);

namespace App\Modules\Shared\Application\Actions;

use App\Modules\Shared\Domain\Models\AccountNotification;
use App\Modules\Shared\Support\TenantContext;
use Carbon\CarbonImmutable;

final readonly class PurgeNotificationsAction
{
    /**
     * One delete per account, for the same reason PurgeActivityLogAction
     * walks them: a scheduled command is bound to no account, so a single
     * statement across the table matches no rows under the policy and the
     * purge quietly stops working.
     *
     * Only read notifications are eligible. An unread one is an outstanding
     * message — expiring it by age would hide something the user has not
     * seen, which is the one failure a notification centre must not have.
     */
    public function execute(CarbonImmutable $today): int
    {
        $retentionDays = (int) config('notifications.retention_days', 90);
        $cutoff = $today->subDays($retentionDays);

        $deleted = 0;

        TenantContext::forEachAccount(function () use ($cutoff, &$deleted): void {
            $deleted += AccountNotification::withoutGlobalScope('user')
                ->whereNotNull('read_at')
                ->where('created_at', '<', $cutoff)
                ->delete();
        });

        return $deleted;
    }
}
