<?php

declare(strict_types=1);

namespace App\Modules\Shared\Application\Actions;

use App\Modules\Shared\Application\Contracts\ActivityRecorderInterface;
use App\Modules\Shared\Domain\Contracts\AccountLocator;
use App\Modules\Shared\Domain\Models\ActivityLog;
use App\Modules\Shared\Support\TenantContext;
use Carbon\CarbonImmutable;

final readonly class PurgeActivityLogAction
{
    public function __construct(
        private ActivityRecorderInterface $recorder,
        private AccountLocator $accounts,
    ) {}

    /**
     * One delete per account rather than one across the table.
     *
     * The single statement this replaces still reads correctly, and under a
     * row-level policy it deletes nothing: a scheduled command is bound to no
     * account, so the policy matches no rows and the purge silently stops
     * working. Walking the accounts is the only way to reach them all without
     * handing the runtime role a way around the policy.
     *
     * A purge cuts the account's hash chain — the oldest surviving row still
     * carries a prev_hash pointing at a row that no longer exists. Rather
     * than rewrite that row's already-hashed prev_hash (which would be
     * exactly the kind of after-the-fact edit the chain exists to catch),
     * record an "activity.purged" entry through the same recorder every
     * other entry goes through, carrying the last deleted row's own hash in
     * its payload. qasa:activity:verify-chain treats a dangling prev_hash as
     * an accepted, documented break when it matches some purge entry's
     * purged_through_hash for that account, instead of flagging it as
     * tampering.
     */
    public function execute(CarbonImmutable $today): int
    {
        $retentionDays = (int) config('activity.retention_days', 730);
        $cutoff = $today->subDays($retentionDays);

        $deleted = 0;

        TenantContext::forEachAccount(function (string $accountId) use ($cutoff, &$deleted): void {
            $lastToPurge = ActivityLog::withoutGlobalScope('user')
                ->where('created_at', '<', $cutoff)
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->first();

            if ($lastToPurge === null) {
                return;
            }

            $purgedThroughHash = $lastToPurge->row_hash;

            $count = ActivityLog::withoutGlobalScope('user')->where('created_at', '<', $cutoff)->delete();
            $deleted += $count;

            $user = $this->accounts->find($accountId);

            if ($user !== null) {
                $this->recorder->record($accountId, null, $user, 'activity.purged', [
                    'purged_count' => $count,
                    'purged_through_hash' => $purgedThroughHash,
                ]);
            }
        });

        return $deleted;
    }
}
