<?php

declare(strict_types=1);

namespace App\Modules\Clients\Application\Services;

use App\Modules\Clients\Application\Contracts\ClientImportRegistry;
use App\Modules\Clients\Domain\Models\Client;
use App\Modules\Clients\Domain\ValueObjects\ClientMatchCriteria;
use Illuminate\Contracts\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Str;

final class EloquentClientImportRegistry implements ClientImportRegistry
{
    public function findExisting(string $ownerId, ClientMatchCriteria $criteria): ?string
    {
        $byExternalId = Client::forUser($ownerId)
            ->where('external_source', $criteria->source)
            ->where('external_id', $criteria->externalId)
            ->value('id');

        if ($byExternalId !== null) {
            return (string) $byExternalId;
        }

        if ($criteria->ico !== null) {
            $byIco = Client::forUser($ownerId)->where('ico', $criteria->ico)->value('id');

            if ($byIco !== null) {
                return (string) $byIco;
            }
        }

        if ($criteria->displayName === null || $criteria->displayName === '') {
            return null;
        }

        $name = Str::lower($criteria->displayName);

        $byName = Client::forUser($ownerId)
            ->where(function (QueryBuilder $query) use ($name): void {
                $query->whereRaw('lower(company_name) = ?', [$name])
                    ->orWhereRaw("lower(trim(coalesce(name, '') || ' ' || coalesce(surname, ''))) = ?", [$name]);
            })
            ->when($criteria->city !== null, fn (QueryBuilder $query) => $query->where('city', $criteria->city))
            ->value('id');

        return $byName === null ? null : (string) $byName;
    }

    public function link(string $clientId, string $source, ?string $externalId): void
    {
        /** @var Client $client */
        $client = Client::query()->findOrFail($clientId);

        $client->forceFill(['external_source' => $source, 'external_id' => $externalId])->save();
    }

    public function backfill(string $clientId, string $source, ?string $externalId): void
    {
        // The "has no source yet" guard rides along in the WHERE rather than
        // in a read-then-write: an import walks thousands of rows and this is
        // on the per-row path.
        Client::query()
            ->whereKey($clientId)
            ->whereNull('external_source')
            ->update(['external_source' => $source, 'external_id' => $externalId]);
    }
}
