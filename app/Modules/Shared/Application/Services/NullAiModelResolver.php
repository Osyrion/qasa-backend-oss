<?php

declare(strict_types=1);

namespace App\Modules\Shared\Application\Services;

use App\Modules\Auth\Domain\Models\User;
use App\Modules\Shared\Application\Contracts\AiModelResolver;

/**
 * Default binding: "no model is configured for this account". Invoicing
 * overrides it with the real resolution (BYOK credential, else platform
 * key) — see AiModelResolver.
 */
final readonly class NullAiModelResolver implements AiModelResolver
{
    public function resolveFor(User $owner): array
    {
        return ['provider' => null, 'model' => null, 'byok' => false];
    }
}
