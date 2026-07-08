<?php

declare(strict_types=1);

namespace App\Modules\TimeTracking\Application\Contracts;

interface ClockifyClientInterface
{
    /**
     * The Clockify user owning the API key.
     *
     * @return array{id: string, activeWorkspace: string|null}|null null when the key is invalid or the API is unreachable
     */
    public function currentUser(string $apiKey): ?array;

    /**
     * One page of finished + running time entries for the user in the given range.
     * Empty array means no (more) entries or a failed request.
     *
     * @return list<array<string, mixed>>
     */
    public function timeEntries(
        string $apiKey,
        string $workspaceId,
        string $clockifyUserId,
        string $start,
        string $end,
        int $page = 1,
    ): array;
}
