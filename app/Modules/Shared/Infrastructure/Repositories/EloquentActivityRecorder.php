<?php

declare(strict_types=1);

namespace App\Modules\Shared\Infrastructure\Repositories;

use App\Modules\Shared\Application\Contracts\ActivityRecorderInterface;
use App\Modules\Shared\Domain\Models\ActivityLog;
use Illuminate\Database\Eloquent\Model;

final class EloquentActivityRecorder implements ActivityRecorderInterface
{
    public function record(
        string $userId,
        ?string $actorId,
        Model $subject,
        string $event,
        array $changes = [],
    ): void {
        ActivityLog::create([
            'user_id' => $userId,
            'actor_id' => $actorId,
            'subject_type' => $subject->getMorphClass(),
            'subject_id' => $subject->getKey(),
            'event' => $event,
            'changes' => $changes,
        ]);
    }
}
