<?php

declare(strict_types=1);

namespace App\Modules\Shared\Application\Services;

use App\Modules\Shared\Application\Contracts\AiFeatureDescriptor;
use App\Modules\Shared\Application\Contracts\AiModelResolver;
use App\Modules\Shared\Domain\Contracts\Account;
use App\Modules\Shared\Domain\Contracts\ProvidesAiPreferences;
use App\Modules\Shared\Domain\Contracts\ProvidesPlanEntitlements;

/**
 * Assembles the account-level AI transparency notice served by
 * GET /api/v1/ai/transparency — the running counterpart of
 * docs/legal/AI_ACT.md, built from whichever AiFeatureDescriptor
 * implementations this edition registered (tag 'ai.features') rather than
 * from a hand-kept list, so the OSS build's shorter feature set describes
 * itself correctly with no edition branch here.
 *
 * The register answers three questions the AI Act makes us answer up front:
 * whether AI runs for this account at all, what each capability sends where,
 * and how to switch it off. It deliberately reports *this account's* state
 * (plan features, own switch, own key), not the product's capabilities in
 * the abstract.
 */
final readonly class AiTransparencyRegister
{
    /**
     * @param  iterable<AiFeatureDescriptor>  $descriptors
     */
    public function __construct(
        private iterable $descriptors,
        private AiModelResolver $modelResolver,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function forOwner(Account&ProvidesAiPreferences&ProvidesPlanEntitlements $owner): array
    {
        $globallyDisabled = (bool) config('qasa.ai.disabled', false);

        $features = [];

        foreach ($this->descriptors as $descriptor) {
            $disclosure = $descriptor->disclosureFor($owner)->toArray();
            $disclosure['enabled'] = $disclosure['enabled'] && ! $globallyDisabled;
            $features[] = $disclosure;
        }

        usort($features, static fn (array $a, array $b): int => strcmp((string) $a['key'], (string) $b['key']));

        $model = $this->modelResolver->resolveFor($owner);

        return [
            'ai_enabled' => ! $globallyDisabled && $features !== [] && in_array(
                true,
                array_column($features, 'enabled'),
                true,
            ),
            'globally_disabled' => $globallyDisabled,
            'provider' => $model['provider'],
            'model' => $model['model'],
            'own_api_key' => $model['byok'],
            'notice' => __('ai.transparency.notice'),
            'human_oversight' => __('ai.transparency.human_oversight'),
            'automated_decision_making' => false,
            'training' => __('ai.transparency.training'),
            'complaints' => __('ai.transparency.complaints'),
            'features' => $features,
        ];
    }
}
