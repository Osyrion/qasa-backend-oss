<?php

declare(strict_types=1);

namespace App\Modules\Clients\Application\Actions;

use App\Modules\Clients\Domain\Models\Client;

readonly class RevokeClientPortalLinkAction
{
    public function execute(Client $client): Client
    {
        $client->forceFill(['portal_token' => null])->save();

        return $client;
    }
}
