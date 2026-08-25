<?php

declare(strict_types=1);

namespace App\Modules\Shared\Application\Contracts;

use App\Modules\Shared\Domain\Contracts\Account;
use App\Modules\Shared\Domain\Contracts\ProvidesAiPreferences;
use App\Modules\Shared\Domain\Contracts\ProvidesPlanEntitlements;

/**
 * Which model actually answers for an account, and on whose key — the one
 * fact the AI Act transparency notice (art. 50 ods. 1) cannot state as a
 * constant, because a BYOK account talks to its own provider under its own
 * contract while everyone else goes through the platform key.
 *
 * Declared in Shared with a no-op default (NullAiModelResolver) so the
 * transparency endpoint stands on its own; Invoicing owns credentials and
 * drivers, so it binds the real implementation over it.
 */
interface AiModelResolver
{
    /**
     * @return array{provider: string|null, model: string|null, byok: bool}
     */
    public function resolveFor(Account&ProvidesAiPreferences&ProvidesPlanEntitlements $owner): array;
}
