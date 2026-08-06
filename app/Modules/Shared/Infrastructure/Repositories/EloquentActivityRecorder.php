<?php

declare(strict_types=1);

namespace App\Modules\Shared\Infrastructure\Repositories;

use App\Modules\Shared\Application\Contracts\ActivityRecorderInterface;
use App\Modules\Shared\Domain\Models\ActivityLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

final class EloquentActivityRecorder implements ActivityRecorderInterface
{
    /**
     * @param  array<string, mixed>  $changes
     */
    public function record(
        string $userId,
        ?string $actorId,
        Model $subject,
        string $event,
        array $changes = [],
    ): void {
        DB::transaction(function () use ($userId, $actorId, $subject, $event, $changes): void {
            // lockForUpdate() inside the transaction serialises concurrent
            // writers for the same account — without it, two requests
            // recording activity at once could both read the same "previous"
            // row and fork the chain instead of extending it.
            $previous = ActivityLog::withoutGlobalScope('user')
                ->where('user_id', $userId)
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->lockForUpdate()
                ->first();

            $prevHash = $previous?->row_hash;
            // activity_log.created_at is a timestamp(0) column — Postgres
            // truncates any sub-second part on write. Truncating here too,
            // before it's hashed, keeps the hashed value and the persisted
            // value identical; hashing the untruncated now() would make
            // every fresh row fail its own verification the moment it's read
            // back from the database.
            $createdAt = now()->startOfSecond();
            $subjectType = $subject->getMorphClass();
            $subjectId = (string) $subject->getKey();

            $rowHash = ActivityLog::computeRowHash(
                $userId,
                $actorId,
                $subjectType,
                $subjectId,
                $event,
                $changes,
                (string) $createdAt->toISOString(),
                $prevHash,
            );

            ActivityLog::create([
                'user_id' => $userId,
                'actor_id' => $actorId,
                'subject_type' => $subjectType,
                'subject_id' => $subjectId,
                'event' => $event,
                'changes' => $changes,
                'created_at' => $createdAt,
                'prev_hash' => $prevHash,
                'row_hash' => $rowHash,
            ]);
        });
    }
}
