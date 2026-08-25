<?php

declare(strict_types=1);

namespace App\Modules\Clients\Application\Services;

use App\Modules\Clients\Application\Contracts\ClientDirectory;
use App\Modules\Clients\Application\Contracts\ClientUsageGuardInterface;
use App\Modules\Clients\Domain\Models\Client;
use App\Modules\Shared\Domain\ValueObjects\PartyProfile;
use App\Modules\Shared\Exceptions\DomainException;

/**
 * The one place outside Clients' own code that reads the `clients` table.
 *
 * Every consumer used to write its own `Client::query()` — eight modules, each
 * re-deciding which global scope to drop and which columns to select. The
 * queries here are the same ones, kept together so the table stays Clients'.
 */
final class EloquentClientDirectory implements ClientDirectory
{
    public function __construct(private readonly ClientUsageGuardInterface $usageGuard) {}

    public function requireForNewDocument(string $clientId, string $ownerId): PartyProfile
    {
        /** @var Client $client */
        $client = Client::forUser($ownerId)->findOrFail($clientId);

        if ($client->isArchived()) {
            throw DomainException::because(__('clients.archived'));
        }

        $this->usageGuard->ensureUsable($client);

        return $client->profile();
    }

    public function assertWithinPlanLimits(?string $clientId): void
    {
        if ($clientId === null) {
            return;
        }

        $client = Client::query()->find($clientId);

        if ($client !== null) {
            $this->usageGuard->ensureUsable($client);
        }
    }

    public function assertUsableForNewRecord(?string $clientId): void
    {
        if ($clientId === null) {
            return;
        }

        $client = Client::query()->find($clientId);

        if ($client === null) {
            return;
        }

        if ($client->isArchived()) {
            throw DomainException::because(__('clients.archived'));
        }

        $this->usageGuard->ensureUsable($client);
    }

    public function requireOwnedProfile(string $clientId, string $ownerId): PartyProfile
    {
        /** @var Client $client */
        $client = Client::forUser($ownerId)->findOrFail($clientId);

        return $client->profile();
    }

    public function existsForAccount(string $clientId, string $ownerId): bool
    {
        return Client::withoutGlobalScope('user')
            ->where('user_id', $ownerId)
            ->whereKey($clientId)
            ->exists();
    }

    public function existsForCurrentAccount(string $clientId): bool
    {
        return Client::query()->whereKey($clientId)->exists();
    }

    public function profilesFor(iterable $clientIds): array
    {
        return $this->profiles($clientIds, includeDeleted: false);
    }

    public function profilesIncludingDeleted(iterable $clientIds): array
    {
        return $this->profiles($clientIds, includeDeleted: true);
    }

    public function requirePeppolId(string $clientId, string $ownerId): ?string
    {
        /** @var Client $client */
        $client = Client::forUser($ownerId)->findOrFail($clientId);

        return $client->peppol_id;
    }

    public function accountHasAny(string $ownerId): bool
    {
        // withoutGlobalScope because the onboarding checklist runs for an
        // account that is not necessarily the authenticated one.
        return Client::withoutGlobalScope('user')->where('user_id', $ownerId)->exists();
    }

    /**
     * @param  iterable<int, string|null>  $clientIds
     * @return array<string, PartyProfile>
     */
    private function profiles(iterable $clientIds, bool $includeDeleted): array
    {
        $ids = array_values(array_unique(array_filter(
            is_array($clientIds) ? $clientIds : iterator_to_array($clientIds, false),
            static fn (?string $id): bool => $id !== null && $id !== '',
        )));

        if ($ids === []) {
            return [];
        }

        $query = $includeDeleted
            ? Client::withoutGlobalScope('user')->withTrashed()
            : Client::query();

        return $query
            ->whereIn('id', $ids)
            ->get()
            ->mapWithKeys(static fn (Client $client): array => [$client->id => $client->profile()])
            ->all();
    }

    public function countForAccount(string $ownerId): int
    {
        return Client::withoutGlobalScope('user')->where('user_id', $ownerId)->count();
    }
}
