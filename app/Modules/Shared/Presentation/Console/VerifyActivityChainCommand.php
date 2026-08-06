<?php

declare(strict_types=1);

namespace App\Modules\Shared\Presentation\Console;

use App\Modules\Shared\Domain\Models\ActivityLog;
use App\Modules\Shared\Support\TenantContext;
use Illuminate\Console\Command;

/**
 * ENGINEERING_GUARDRAILS_PLAN.md part C: walks each account's activity_log
 * hash chain and reports the first row where either the row's own content no
 * longer matches its stored hash (tampering), or its prev_hash does not lead
 * back to the previous row and is not a documented purge boundary (a forged
 * or reordered insert).
 */
class VerifyActivityChainCommand extends Command
{
    protected $signature = 'qasa:activity:verify-chain
        {--account= : Verify only this account id, instead of every account}';

    protected $description = "Verify every account's activity log hash chain is intact and unmodified";

    public function handle(): int
    {
        /** @var string|null $accountOption */
        $accountOption = $this->option('account');
        $ok = true;

        $verifyOne = function (string $accountId) use (&$ok): void {
            [$broken, $count] = $this->verifyAccount($accountId);

            if ($broken !== null) {
                $ok = false;
                $this->error("Account {$accountId}: BROKEN at entry {$broken['id']} ({$broken['reason']}), after {$count} verified record(s).");
            } else {
                $this->info("Account {$accountId}: chain intact ({$count} record(s)).");
            }
        };

        if ($accountOption !== null) {
            TenantContext::for($accountOption, function () use ($verifyOne, $accountOption): void {
                $verifyOne($accountOption);
            });
        } else {
            TenantContext::forEachAccount($verifyOne);
        }

        return $ok ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @return array{0: array{id: string, reason: string}|null, 1: int}
     */
    private function verifyAccount(string $accountId): array
    {
        $entries = ActivityLog::withoutGlobalScope('user')
            ->where('user_id', $accountId)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        /** @var list<string> $purgedThroughHashes */
        $purgedThroughHashes = $entries
            ->filter(fn (ActivityLog $entry): bool => $entry->event === 'activity.purged')
            ->map(fn (ActivityLog $entry): mixed => $entry->changes['purged_through_hash'] ?? null)
            ->filter(fn (mixed $hash): bool => is_string($hash))
            ->values()
            ->all();

        $expectedPrevHash = null;
        $verifiedCount = 0;

        foreach ($entries as $entry) {
            if ($entry->row_hash === null) {
                // Unchained: pre-dates this feature (not yet backfilled) or a
                // fixture inserted directly, bypassing the recorder — it
                // does not participate in the chain either way.
                continue;
            }

            $recomputed = ActivityLog::computeRowHash(
                $entry->user_id,
                $entry->actor_id,
                $entry->subject_type,
                $entry->subject_id,
                $entry->event,
                $entry->changes ?? [],
                (string) $entry->created_at?->toISOString(),
                $entry->prev_hash,
            );

            if (! hash_equals($recomputed, $entry->row_hash)) {
                return [['id' => $entry->id, 'reason' => "row_hash does not match this entry's own content"], $verifiedCount];
            }

            $linkedProperly = $entry->prev_hash === $expectedPrevHash
                || ($entry->prev_hash !== null && in_array($entry->prev_hash, $purgedThroughHashes, true));

            if (! $linkedProperly) {
                return [['id' => $entry->id, 'reason' => 'prev_hash matches neither the previous entry nor a documented purge boundary'], $verifiedCount];
            }

            $expectedPrevHash = $entry->row_hash;
            $verifiedCount++;
        }

        return [null, $verifiedCount];
    }
}
