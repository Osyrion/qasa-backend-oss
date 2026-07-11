<?php

declare(strict_types=1);

namespace App\Modules\Calendar\Application\Contracts;

use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

/**
 * Guards against overlapping events for a given user.
 *
 * The OSS edition binds a no-op implementation (AllowOverlapPolicy) — overlap
 * checking is out of scope for OSS. The SaaS edition rebinds this interface
 * (via bootstrap/providers.edition.php) to an implementation that enforces
 * non-overlapping events, without any change to the calling create/update
 * actions or import pipeline.
 */
interface OverlapPolicyInterface
{
    /**
     * @throws ValidationException When the interval overlaps an existing event.
     */
    public function assertAllowed(
        string $userId,
        CarbonImmutable $startsAt,
        CarbonImmutable $endsAt,
        ?string $ignoreEventId = null,
    ): void;
}
