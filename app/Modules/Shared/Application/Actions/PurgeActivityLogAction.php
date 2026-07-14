<?php

declare(strict_types=1);

namespace App\Modules\Shared\Application\Actions;

use App\Modules\Shared\Domain\Models\ActivityLog;
use Carbon\CarbonImmutable;

final readonly class PurgeActivityLogAction
{
    public function execute(CarbonImmutable $today): int
    {
        $retentionDays = (int) config('activity.retention_days', 730);
        $cutoff = $today->subDays($retentionDays);

        return ActivityLog::withoutGlobalScope('user')->where('created_at', '<', $cutoff)->delete();
    }
}
