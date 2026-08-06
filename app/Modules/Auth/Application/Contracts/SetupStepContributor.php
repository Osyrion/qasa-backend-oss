<?php

declare(strict_types=1);

namespace App\Modules\Auth\Application\Contracts;

/**
 * Lets a module add its own step to the onboarding checklist without Auth
 * knowing it exists — the same arrangement as DashboardStatsContributor.
 * Implementations are tagged 'setup.steps' in their own service provider,
 * so the OSS edition simply has fewer steps rather than a broken reference.
 */
interface SetupStepContributor
{
    /**
     * Extra checklist steps, appended after the core ones.
     *
     * Contributed steps should be optional unless the account genuinely
     * cannot invoice without them: `completed` gates the whole wizard, and
     * a module-specific step blocking it would make onboarding depend on
     * which plan the account is on.
     *
     * @return list<array{key: string, done: bool, optional: bool}>
     */
    public function stepsFor(string $ownerId): array;
}
