<?php

declare(strict_types=1);

namespace App\Modules\Calendar\Application\Services;

use App\Modules\Calendar\Application\Contracts\OverlapPolicyInterface;
use Carbon\CarbonImmutable;

/**
 * OSS edition: overlap checking is out of scope, so every interval is allowed.
 */
class AllowOverlapPolicy implements OverlapPolicyInterface
{
    public function assertAllowed(
        string $userId,
        CarbonImmutable $startsAt,
        CarbonImmutable $endsAt,
        ?string $ignoreEventId = null,
    ): void {
        // No-op — see interface docblock for the SaaS rebinding seam.
    }
}
