<?php

declare(strict_types=1);

namespace App\Modules\Auth\Application\Services;

use App\Modules\Auth\Application\Contracts\AccountExportContributor;
use App\Modules\Auth\Domain\Models\User;
use App\Modules\Shared\Domain\Models\AccountNotification;
use App\Modules\Shared\Domain\Models\ActivityLog;

/**
 * Assembles a complete export of an account's data (GDPR data portability).
 * Every query is scoped explicitly via forUser($ownerId) so it works outside
 * an authenticated request context too.
 */
class AccountExportService
{
    /**
     * @param  iterable<AccountExportContributor>  $contributors  Sections owned by other modules.
     */
    public function __construct(private readonly iterable $contributors = []) {}

    /**
     * A team member's own personal data, rather than the account's records
     * (docs/plans/GDPR_COMPLIANCE_PLAN.md, phase 4B).
     *
     * The account's clients and invoices are deliberately absent: they are
     * the owner's records, who is the controller for them. A member is a data
     * subject in their own right, but only of the three things here — an
     * export is a right of access, not a permission bypass.
     *
     * **No contributor hook, on purpose.** The plan called for a second
     * method on AccountExportContributor, and writing it showed there is
     * nothing for it to return: time_entries.user_id and trips.user_id are
     * the *account*, and neither table carries member attribution at all
     * (create_trips_table even records that driver_user_id is future work).
     * Every implementation would answer `[]`, so the hook would be an
     * abstraction with no members. Add it the day a premium table can
     * actually name which member a row belongs to.
     *
     * @return array<string, mixed>
     */
    public function buildForMember(User $member): array
    {
        return [
            'exported_at' => now()->toISOString(),
            'profile' => $member->toArray(),
            // What the member did, not what happened on the account.
            'activity_log' => ActivityLog::query()->where('actor_id', $member->id)->get()->toArray(),
            'notifications' => AccountNotification::forRecipient($member->id)->get()->toArray(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function build(User $user): array
    {
        $ownerId = $user->accountOwnerId();
        $owner = $user->accountOwner();

        // Only what Auth and Shared own. Everything else — clients, orders,
        // the six invoicing sections, and the premium modules' — arrives
        // through contributors, so the module that owns a table is the one
        // that decides what an export of it contains.
        $export = [
            'exported_at' => now()->toISOString(),
            'profile' => $owner->toArray(),
            // Shared owns these two. Both are the user's own record of what
            // happened on the account, which is squarely what Art. 20 is about.
            'activity_log' => ActivityLog::forUser($ownerId)->get()->toArray(),
            'notifications' => AccountNotification::forUser($ownerId)->get()->toArray(),
        ];

        foreach ($this->contributors as $contributor) {
            $export = [...$export, ...$contributor->exportFor($ownerId)];
        }

        return $export;
    }
}
