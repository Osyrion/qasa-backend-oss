<?php

declare(strict_types=1);

namespace App\Modules\Clients\Application\Services;

use App\Modules\Clients\Application\Contracts\ClientAuthorization;
use App\Modules\Clients\Domain\Enums\ClientAbility;
use App\Modules\Clients\Domain\Models\Client;
use App\Modules\Shared\Domain\Contracts\Actor;
use Illuminate\Support\Facades\Gate;

/**
 * Answers the question by asking ClientPolicy, so the rule has one home.
 */
final readonly class GateClientAuthorization implements ClientAuthorization
{
    public function allows(Actor $actor, ClientAbility $ability, string $clientId): bool
    {
        $client = Client::query()->find($clientId);

        if (! $client instanceof Client) {
            return false;
        }

        return Gate::forUser($actor)->allows($ability->value, $client);
    }
}
