<?php

declare(strict_types=1);

namespace App\Modules\Auth\Application\Actions;

use App\Modules\Auth\Domain\Models\User;
use App\Modules\Shared\Application\Contracts\ActivityRecorderInterface;
use App\Modules\Shared\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * The second half of account deletion (docs/plans/GDPR_COMPLIANCE_PLAN.md,
 * phase 1). DeleteAccountAction soft-deletes; this anonymises what is left
 * once the grace period has run out.
 *
 * Anonymisation, not deletion, and the distinction is the whole design. An
 * issued invoice is an accounting record with its own retention obligation,
 * and it holds a foreign key to the account that issued it — so the row has
 * to stay. What can go is everything on it that identifies a person. That
 * only works because a document does not read its supplier or client off
 * these columns: IssueInvoiceAction freezes supplier_snapshot /
 * client_snapshot at issue, and every consumer (PDF, UBL/ISDOC exports, VAT
 * control statement) reads the snapshot. The document survives intact while
 * the identity behind it does not.
 *
 * One delete pass per account rather than one statement across the table,
 * for the reason PurgeActivityLogAction spells out: a scheduled command is
 * bound to no account, and under a row-level policy an unbound statement
 * matches nothing and the purge silently stops working.
 *
 * Team members need no special handling here, which is what keeps this in
 * the core edition without naming a premium class. The users policy is
 * `coalesce(owner_id, id) = <bound account>`, so binding to an account makes
 * the owner row *and* its members visible in the same query — a member
 * soft-deleted alongside their owner is anonymised by the same pass.
 */
final readonly class PurgeDeletedAccountsAction
{
    public function __construct(
        private ActivityRecorderInterface $recorder,
    ) {}

    /**
     * @return int Number of user rows anonymised
     */
    public function execute(CarbonImmutable $today, bool $dryRun = false): int
    {
        $cutoff = $today->subDays((int) config('gdpr.account_purge_grace_days', 30));

        $purged = 0;

        // The edition's model, not this class's own import. Two things break
        // otherwise: the SaaS model carries owner_id (so team members would
        // be invisible to the query), and tokens() filters on tokenable_type,
        // which holds whichever class actually created the token — a core
        // User instance deletes nothing a SaaS User issued.
        /** @var class-string<User> $model */
        $model = config('auth.providers.users.model', User::class);

        TenantContext::forEachAccount(function () use ($model, $cutoff, $dryRun, &$purged): void {
            $users = $model::withTrashed()
                ->whereNotNull('deleted_at')
                ->where('deleted_at', '<', $cutoff)
                ->where('name', '!=', User::ANONYMISED)
                ->get();

            foreach ($users as $user) {
                $purged++;

                if (! $dryRun) {
                    $this->anonymise($user);
                }
            }
        });

        return $purged;
    }

    private function anonymise(User $user): void
    {
        $logoPath = $user->logo_path;

        DB::transaction(function () use ($user): void {
            // Tokens are already revoked by DeleteAccountAction; anything
            // here was created between the two steps — by a team member, or
            // by a session that was still live when the account went.
            $user->tokens()->delete();
            DB::table('sessions')->where('user_id', $user->id)->delete();

            $user->forceFill([
                'name' => User::ANONYMISED,
                'surname' => '',
                // .invalid is reserved (RFC 2606), so this can never be
                // delivered to, and keying it on the id keeps the unique
                // index satisfied while releasing the real address for reuse.
                'email' => "deleted-{$user->id}@invalid",
                'title' => null,
                'phone' => null,
                // Cleared with the number it attested to. Left behind it
                // would be a row claiming a verified phone it no longer has,
                // and account_for_phone() would be reading a fossil.
                'phone_verified_at' => null,
                'address' => null,
                'city' => null,
                'postal_code' => null,
                'ico' => null,
                'dic' => null,
                'vat_id' => null,
                'website' => null,
                'company_name' => null,
                'invoice_footer_text' => null,
                'color' => null,
                'password' => null,
                'remember_token' => null,
                'google_id' => null,
                // Only ever a Google URL — there is no avatar upload, so
                // there is no file to remove, only a column to clear.
                'avatar_path' => null,
                'logo_path' => null,
                'clockify_api_key' => null,
                'clockify_workspace_id' => null,
                'two_factor_secret' => null,
                'two_factor_recovery_codes' => null,
                'two_factor_confirmed_at' => null,
            ])->save();

            // Recorded through the same recorder as every other entry so the
            // account's hash chain stays continuous — and recorded *after*
            // the anonymisation, so nothing of what was removed is copied
            // into the log that documents its removal. actor_id stays null:
            // no one performed this, a schedule did.
            $this->recorder->record($user->accountOwnerId(), null, $user, 'account.purged');
        });

        // Outside the transaction: a filesystem delete cannot be rolled back,
        // so doing it inside would leave the file gone but the row intact if
        // the commit failed. country is kept — it is the document retention
        // key (config gdpr.document_retention_years), not an identifier, and
        // the model forbids changing it once set anyway.
        $this->deleteLogo($user->id, $logoPath);
    }

    private function deleteLogo(string $userId, ?string $logoPath): void
    {
        $disk = Storage::disk('public');

        if ($logoPath !== null && $logoPath !== '' && ! Str::startsWith($logoPath, ['http://', 'https://'])) {
            $disk->delete($logoPath);
        }

        // AuthController::uploadLogo stores under logos/{id}, one directory
        // per account, and keeps every version uploaded — logo_path names
        // only the current one.
        $disk->deleteDirectory("logos/{$userId}");
    }
}
