<?php

declare(strict_types=1);

namespace App\Modules\Clients\Application\Services;

use App\Modules\Clients\Application\Contracts\ClientUsageRoster;
use App\Modules\Clients\Domain\Enums\ClientRole;
use App\Modules\Clients\Domain\Models\Client;

final class EloquentClientUsageRoster implements ClientUsageRoster
{
    public function oldestActiveIds(string $ownerId, ClientRole $role, int $limit): array
    {
        /** @var list<string> $ids */
        $ids = Client::forUser($ownerId)
            ->active()
            ->where($role->column(), true)
            ->orderBy('created_at')
            ->orderBy('id')
            ->limit($limit)
            ->pluck('id')
            ->map(static fn (mixed $id): string => (string) $id)
            ->all();

        return $ids;
    }
}
