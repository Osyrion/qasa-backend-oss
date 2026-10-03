<?php

declare(strict_types=1);

namespace App\Modules\Shared\Application\Actions;

use App\Modules\Shared\Application\Contracts\RequestMetadataPurger;
use App\Modules\Shared\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Phase 2 of docs/plans/GDPR_COMPLIANCE_PLAN.md. An IP address is personal
 * data and three tables held one indefinitely.
 *
 * Two different treatments, because the rows are two different things:
 *
 * - A **session** row past its retention is nothing *but* stale metadata, so
 *   it goes entirely. Laravel's own cleanup is a lottery on the session
 *   driver, which is not a retention guarantee.
 * - A **token** is an access credential that outlives its metadata. Deleting
 *   it would log an integration out to satisfy a rule about IP addresses, so
 *   only ip_address/user_agent are cleared and the token keeps working.
 *
 * Other modules clear their own tables through the 'privacy.purge' tag —
 * Shared must not name Admin, and each contributor owns its own retention
 * window (the admin audit's is deliberately much longer).
 */
final readonly class PurgeRequestMetadataAction
{
    /**
     * @param  iterable<RequestMetadataPurger>  $contributors  Tables owned by other modules.
     */
    public function __construct(private iterable $contributors = []) {}

    /**
     * @return int Rows deleted or cleared
     */
    public function execute(CarbonImmutable $now): int
    {
        $cutoff = $now->subDays((int) config('gdpr.request_metadata_retention_days', 90));

        $affected = $this->purgeSessions($cutoff) + $this->purgeTokenMetadata($cutoff);

        foreach ($this->contributors as $contributor) {
            $affected += $contributor->purgeAsOf($now);
        }

        return $affected;
    }

    /**
     * sessions carries no RLS policy (it is framework-managed and never read
     * as tenant data — see the allowlist in RowLevelSecurityTest), so one
     * statement reaches every row. last_activity is a unix timestamp, not a
     * datetime column.
     */
    private function purgeSessions(CarbonImmutable $cutoff): int
    {
        return DB::table('sessions')->where('last_activity', '<', $cutoff->getTimestamp())->delete();
    }

    /**
     * personal_access_tokens does carry a policy, so this walks the accounts
     * one at a time — the reason PurgeActivityLogAction spells out: an
     * unbound statement matches nothing and the purge silently stops working.
     *
     * A token that was never used has no last_used_at, and its age is then
     * its creation date; without the coalesce those rows would keep their
     * metadata forever, which is precisely the case this exists to fix.
     *
     * The owner is named in the statement as well as left to the policy, for
     * the reason Integrations\PurgeWebhookDeliveriesAction spells out: a
     * policy's EXISTS becomes a hashed SubPlan, which Postgres can only apply
     * as a row filter, so an account-at-a-time sweep with no owner predicate
     * scans the entire table on every account's turn. The subquery is exactly
     * the policy's own condition written where the planner can use it, so the
     * two cannot select different rows — DB::table, not Eloquent, precisely so
     * that no scope (users soft-deletes) can make them diverge.
     *
     * tokenable_id alone rather than the (tokenable_type, tokenable_id) pair:
     * the morph string for the account model is edition-dependent and a
     * retention job has no business hardcoding it. 2026_09_03_000001 adds the
     * index that makes filtering on the id alone selective.
     */
    private function purgeTokenMetadata(CarbonImmutable $cutoff): int
    {
        $cleared = 0;

        TenantContext::forEachAccount(function () use ($cutoff, &$cleared): void {
            $cleared += DB::table('personal_access_tokens')
                ->whereIn('tokenable_id', DB::table('users')->select('id'))
                ->whereRaw('coalesce(last_used_at, created_at) < ?', [$cutoff])
                ->where(function ($query): void {
                    $query->whereNotNull('ip_address')->orWhereNotNull('user_agent');
                })
                ->update(['ip_address' => null, 'user_agent' => null]);
        });

        return $cleared;
    }
}
