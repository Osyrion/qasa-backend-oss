<?php

declare(strict_types=1);

use App\Modules\Shared\Domain\Models\ActivityLog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ENGINEERING_GUARDRAILS_PLAN.md part C: a tamper-evident audit trail needs
 * more than "nobody has an UPDATE/DELETE endpoint for it" — a hash chain
 * makes a row edited directly in the database (or restored from an out of
 * band backup) detectable by qasa:activity:verify-chain, per account.
 *
 * Runs on the pgsql_system connection (OwnerConnection::routeSchemaCommands)
 * which bypasses Row Level Security, so the backfill below can walk every
 * account's rows in one pass — the same reasoning
 * make_invoice_number_nullable's down() already relies on.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('activity_log', function (Blueprint $table): void {
            // Both nullable: existing rows are null until the backfill below
            // runs, and rows inserted directly via the factory in tests
            // (bypassing EloquentActivityRecorder) stay unchained on purpose
            // — qasa:activity:verify-chain treats an unchained prefix as
            // pre-dating the feature, not as tampering.
            $table->string('prev_hash', 64)->nullable()->after('changes');
            $table->string('row_hash', 64)->nullable()->after('prev_hash');
        });

        $this->backfillChain();
    }

    public function down(): void
    {
        Schema::table('activity_log', function (Blueprint $table): void {
            $table->dropColumn(['prev_hash', 'row_hash']);
        });
    }

    /**
     * One pass, ordered by (user_id, created_at, id) so each account's chain
     * is built strictly in the order its entries actually happened.
     */
    private function backfillChain(): void
    {
        /** @var array<string, string> $lastHashByAccount */
        $lastHashByAccount = [];

        ActivityLog::withoutGlobalScope('user')
            ->orderBy('user_id')
            ->orderBy('created_at')
            ->orderBy('id')
            ->each(function (ActivityLog $entry) use (&$lastHashByAccount): void {
                $prevHash = $lastHashByAccount[$entry->user_id] ?? null;

                $rowHash = ActivityLog::computeRowHash(
                    $entry->user_id,
                    $entry->actor_id,
                    $entry->subject_type,
                    $entry->subject_id,
                    $entry->event,
                    $entry->changes ?? [],
                    (string) $entry->created_at?->toISOString(),
                    $prevHash,
                );

                $entry->forceFill(['prev_hash' => $prevHash, 'row_hash' => $rowHash])->saveQuietly();

                $lastHashByAccount[$entry->user_id] = $rowHash;
            });
    }
};
