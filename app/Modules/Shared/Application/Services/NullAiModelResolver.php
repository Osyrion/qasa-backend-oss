<?php

declare(strict_types=1);

namespace App\Modules\Shared\Application\Services;

use App\Modules\Shared\Application\Contracts\AiModelResolver;
use App\Modules\Shared\Domain\Contracts\Account;
use App\Modules\Shared\Domain\Contracts\ProvidesAiPreferences;
use App\Modules\Shared\Domain\Contracts\ProvidesPlanEntitlements;

/**
 * Default binding: "no model is configured for this account". Invoicing
 * overrides it with the real resolution (BYOK credential, else platform
 * key) — see AiModelResolver.
 */
final readonly class NullAiModelResolver implements AiModelResolver
{
    public function resolveFor(Account&ProvidesAiPreferences&ProvidesPlanEntitlements $owner): array
    {
        return ['provider' => null, 'model' => null, 'byok' => false];
    }
}
