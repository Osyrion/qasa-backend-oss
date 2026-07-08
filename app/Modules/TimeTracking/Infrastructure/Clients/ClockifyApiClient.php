<?php

declare(strict_types=1);

namespace App\Modules\TimeTracking\Infrastructure\Clients;

use App\Modules\TimeTracking\Application\Contracts\ClockifyClientInterface;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Clockify REST API v1 client. https://docs.clockify.me/
 */
class ClockifyApiClient implements ClockifyClientInterface
{
    public const int PAGE_SIZE = 200;

    public function currentUser(string $apiKey): ?array
    {
        try {
            $response = $this->request($apiKey)->get('/user');
        } catch (Throwable) {
            return null;
        }

        if (! $response->successful() || $response->json('id') === null) {
            return null;
        }

        return [
            'id' => (string) $response->json('id'),
            'activeWorkspace' => $response->json('activeWorkspace'),
        ];
    }

    public function timeEntries(
        string $apiKey,
        string $workspaceId,
        string $clockifyUserId,
        string $start,
        string $end,
        int $page = 1,
    ): array {
        try {
            $response = $this->request($apiKey)->get(
                sprintf('/workspaces/%s/user/%s/time-entries', $workspaceId, $clockifyUserId),
                [
                    'start' => $start,
                    'end' => $end,
                    'page' => $page,
                    'page-size' => self::PAGE_SIZE,
                    'in-progress' => 'false',
                ],
            );
        } catch (Throwable) {
            return [];
        }

        if (! $response->successful()) {
            return [];
        }

        /** @var list<array<string, mixed>> */
        return (array) $response->json();
    }

    private function request(string $apiKey): PendingRequest
    {
        return Http::baseUrl((string) config('services.clockify.base_url'))
            ->withHeaders(['X-Api-Key' => $apiKey])
            ->timeout(10)
            ->retry(2, 200, throw: false);
    }
}
