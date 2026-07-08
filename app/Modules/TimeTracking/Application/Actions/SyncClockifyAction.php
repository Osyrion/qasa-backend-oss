<?php

declare(strict_types=1);

namespace App\Modules\TimeTracking\Application\Actions;

use App\Modules\Auth\Domain\Models\User;
use App\Modules\Orders\Domain\Models\Order;
use App\Modules\Shared\Exceptions\DomainException;
use App\Modules\TimeTracking\Application\Contracts\ClockifyClientInterface;
use App\Modules\TimeTracking\Application\DTOs\ClockifySyncData;
use App\Modules\TimeTracking\Domain\Models\TimeEntry;
use App\Modules\TimeTracking\Infrastructure\Clients\ClockifyApiClient;
use Illuminate\Support\Carbon;

readonly class SyncClockifyAction
{
    public function __construct(
        private ClockifyClientInterface $client,
    ) {}

    /**
     * Pull finished Clockify time entries in the range into the given order.
     * Idempotent: re-sync updates existing rows (matched by external id),
     * already invoiced entries are left untouched.
     *
     * @return array{created: int, updated: int, skipped: int}
     *
     * @throws DomainException
     */
    public function execute(User $user, Order $order, ClockifySyncData $data): array
    {
        $apiKey = $user->clockify_api_key;

        if ($apiKey === null || $apiKey === '') {
            throw DomainException::because(__('time_tracking.clockify_api_key_missing'));
        }

        $clockifyUser = $this->client->currentUser($apiKey);

        if ($clockifyUser === null) {
            throw DomainException::because(__('time_tracking.clockify_api_key_invalid'));
        }

        $workspaceId = $user->clockify_workspace_id ?? $clockifyUser['activeWorkspace'];

        if ($workspaceId === null || $workspaceId === '') {
            throw DomainException::because(__('time_tracking.clockify_workspace_missing'));
        }

        $start = Carbon::parse($data->date_from)->startOfDay()->toIso8601ZuluString();
        $end = Carbon::parse($data->date_to)->endOfDay()->toIso8601ZuluString();

        $created = 0;
        $updated = 0;
        $skipped = 0;
        $page = 1;

        do {
            $entries = $this->client->timeEntries($apiKey, $workspaceId, $clockifyUser['id'], $start, $end, $page);

            foreach ($entries as $entry) {
                $result = $this->syncEntry($user, $order, $entry);
                match ($result) {
                    'created' => $created++,
                    'updated' => $updated++,
                    default => $skipped++,
                };
            }

            $page++;
        } while (count($entries) === ClockifyApiClient::PAGE_SIZE);

        return ['created' => $created, 'updated' => $updated, 'skipped' => $skipped];
    }

    /**
     * @param  array<string, mixed>  $entry
     */
    private function syncEntry(User $user, Order $order, array $entry): string
    {
        $externalId = $entry['id'] ?? null;
        $startRaw = $entry['timeInterval']['start'] ?? null;
        $endRaw = $entry['timeInterval']['end'] ?? null;

        // Skip malformed and still-running entries
        if (! is_string($externalId) || ! is_string($startRaw) || ! is_string($endRaw)) {
            return 'skipped';
        }

        $existing = TimeEntry::withoutGlobalScope('user')
            ->where('user_id', $user->accountOwnerId())
            ->where('source', 'clockify')
            ->where('external_id', $externalId)
            ->first();

        if ($existing?->is_invoiced) {
            return 'skipped';
        }

        $startedAt = Carbon::parse($startRaw);
        $endedAt = Carbon::parse($endRaw);

        $attributes = [
            'order_id' => $order->id,
            'description' => trim((string) ($entry['description'] ?? '')) ?: 'Clockify import',
            'started_at' => $startedAt,
            'ended_at' => $endedAt,
            'duration_seconds' => (int) $startedAt->diffInSeconds($endedAt),
            'is_billable' => (bool) ($entry['billable'] ?? true),
        ];

        if ($existing !== null) {
            $existing->update($attributes);

            return 'updated';
        }

        TimeEntry::create($attributes + [
            'user_id' => $user->accountOwnerId(),
            'source' => 'clockify',
            'external_id' => $externalId,
            'is_invoiced' => false,
        ]);

        return 'created';
    }
}
