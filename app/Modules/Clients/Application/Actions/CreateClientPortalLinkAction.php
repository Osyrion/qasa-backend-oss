<?php

declare(strict_types=1);

namespace App\Modules\Clients\Application\Actions;

use App\Modules\Clients\Domain\Models\Client;
use Illuminate\Support\Str;

/**
 * Idempotent, exactly like CreateInvoicePublicLinkAction: an existing token
 * is returned as-is unless $regenerate is set, in which case a fresh one
 * replaces it and every link the client already has stops resolving.
 *
 * That is the intended revocation path — there is no separate "disable"
 * state, because a token that exists but is refused is a distinction only
 * the implementation cares about.
 */
readonly class CreateClientPortalLinkAction
{
    public function execute(Client $client, bool $regenerate = false): Client
    {
        if ($client->portal_token !== null && ! $regenerate) {
            return $client;
        }

        $client->forceFill(['portal_token' => Str::random(64)])->save();

        return $client;
    }
}
