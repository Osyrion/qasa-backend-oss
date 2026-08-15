<?php

declare(strict_types=1);

namespace App\Modules\Shared\Application\Contracts;

use App\Modules\Auth\Domain\Models\User;
use App\Modules\Shared\Application\DTOs\AiFeatureDisclosure;

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
     * $owner is the account owner (not necessarily the caller) — `enabled`
     * reflects that account's switches, plan features and credentials.
     */
    public function disclosureFor(User $owner): AiFeatureDisclosure;
}
