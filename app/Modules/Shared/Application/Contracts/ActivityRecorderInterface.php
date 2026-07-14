<?php

declare(strict_types=1);

namespace App\Modules\Shared\Application\Contracts;

use Illuminate\Database\Eloquent\Model;

/**
 * Modules depend on this contract rather than the ActivityLog model
 * directly, so ModuleBoundariesTest's Application-layer isolation still
 * holds even though the concrete table lives outside any one module.
 */
interface ActivityRecorderInterface
{
    /**
     * @param  array<string, mixed>  $changes  Old/new values; shape varies per event
     */
    public function record(
        string $userId,
        ?string $actorId,
        Model $subject,
        string $event,
        array $changes = [],
    ): void;
}
