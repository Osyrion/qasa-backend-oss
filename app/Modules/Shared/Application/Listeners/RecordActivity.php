<?php

declare(strict_types=1);

namespace App\Modules\Shared\Application\Listeners;

use App\Modules\Auth\Domain\Models\User;
use App\Modules\Shared\Application\Contracts\ActivityRecorderInterface;
use App\Modules\Shared\Application\Services\ActivityEventRegistry;
use Illuminate\Database\Eloquent\Model;

/**
 * Single listener for every activity-worthy domain event, same shape as
 * other event listeners — one class registered against many event classes
 * rather than a near-identical listener per event.
 *
 * What each event maps to lives in ActivityEventRegistry, populated by the
 * owning module's service provider.
 */
final class RecordActivity
{
    public function __construct(
        private readonly ActivityRecorderInterface $recorder,
        private readonly ActivityEventRegistry $registry,
    ) {}

    public function handle(object $event): void
    {
        $subject = $this->registry->subjectFor($event);
        $eventName = $this->registry->wireNameFor($event);

        if ($subject === null || $eventName === null) {
            return;
        }

        $userId = $this->tenantIdFor($subject);

        if ($userId === null) {
            return;
        }

        $actorId = auth()->id();

        $this->recorder->record(
            $userId,
            is_string($actorId) ? $actorId : null,
            $subject,
            $eventName,
            $this->registry->changesFor($event),
        );
    }

    /**
     * Tenant (account owner) scope for the log entry. Client/Order/Invoice
     * subjects carry their own user_id column; User subjects (registration,
     * team membership) resolve it via accountOwnerId() instead.
     */
    private function tenantIdFor(Model $subject): ?string
    {
        if ($subject instanceof User) {
            return $subject->accountOwnerId();
        }

        $userId = $subject->getAttribute('user_id');

        return is_string($userId) ? $userId : null;
    }
}
