<?php

declare(strict_types=1);

namespace App\Modules\Shared\Application\Contracts;

use App\Modules\Shared\Application\DTOs\AiFeatureDisclosure;
use App\Modules\Shared\Domain\Contracts\Account;
use App\Modules\Shared\Domain\Contracts\ProvidesAiPreferences;
use App\Modules\Shared\Domain\Contracts\ProvidesPlanEntitlements;

/**
 * Every capability that sends account data to an LLM describes itself here,
 * and its own module tags the implementation 'ai.features' in its service
 * provider — the same arrangement as DashboardStatsContributor. Shared then
 * serves the whole list (AiTransparencyRegister) without naming a premium
 * module: the OSS edition simply has fewer descriptors.
 *
 * Adding an outbound AI call without a descriptor is the failure mode this
 * contract exists to prevent — the transparency notice we owe users under
 * the AI Act would silently stop matching what the system actually does.
 * tests/Architecture/AiFeatureCoverageTest.php fails the build for an
 * AiAssistantServiceInterface consumer with no descriptor behind it.
 */
interface AiFeatureDescriptor
{
    /**
     * $owner is the account (a team member is fine — plan entitlements
     * already resolve to the owner) — `enabled` reflects that account's
     * switches, plan features and credentials.
     */
    public function disclosureFor(Account&ProvidesAiPreferences&ProvidesPlanEntitlements $owner): AiFeatureDisclosure;
}
