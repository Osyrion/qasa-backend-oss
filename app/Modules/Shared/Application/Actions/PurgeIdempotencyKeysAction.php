<?php

declare(strict_types=1);

namespace App\Modules\Shared\Application\Actions;

use App\Modules\Shared\Domain\Models\IdempotencyKey;
use Carbon\CarbonImmutable;

final readonly class PurgeIdempotencyKeysAction
{
    private const TTL_HOURS = 24;

    public function execute(CarbonImmutable $now): int
    {
        return IdempotencyKey::where('created_at', '<', $now->subHours(self::TTL_HOURS))->delete();
    }
}
