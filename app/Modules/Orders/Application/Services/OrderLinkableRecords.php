<?php

declare(strict_types=1);

namespace App\Modules\Orders\Application\Services;

use App\Modules\Orders\Domain\Models\Order;
use App\Modules\Shared\Application\Contracts\LinkableRecordResolver;

final class OrderLinkableRecords implements LinkableRecordResolver
{
    public function recordTypes(): array
    {
        return ['order'];
    }

    public function existsForAccount(string $recordType, string $recordId, string $ownerId): bool
    {
        return $recordType === 'order'
            && Order::query()
                ->withoutGlobalScope('user')
                ->where('user_id', $ownerId)
                ->whereKey($recordId)
                ->exists();
    }
}
