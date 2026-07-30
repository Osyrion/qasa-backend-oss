<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Application\Actions;

use App\Modules\Auth\Domain\Models\User;
use App\Modules\Invoicing\Application\DTOs\UpsertAiCredentialData;
use App\Modules\Invoicing\Domain\Enums\AiProvider;
use App\Modules\Invoicing\Domain\Models\AiCredential;

/**
 * Saving a new key over an old one resets verified_at/last_error — the
 * previous key's verification result says nothing about whether the new
 * one works.
 */
final class UpsertAiCredentialAction
{
    public function execute(User $owner, AiProvider $provider, UpsertAiCredentialData $data): AiCredential
    {
        return AiCredential::query()->updateOrCreate(
            ['user_id' => $owner->accountOwnerId(), 'provider' => $provider->value],
            ['api_key' => $data->api_key, 'verified_at' => null, 'last_error' => null],
        );
    }
}
