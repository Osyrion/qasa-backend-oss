<?php

declare(strict_types=1);

namespace App\Modules\Clients\Application\Services;

use App\Modules\Clients\Application\Contracts\ClientAutoSendPreference;
use App\Modules\Clients\Domain\Models\Client;

final class EloquentClientAutoSendPreference implements ClientAutoSendPreference
{
    public function set(string $clientId, bool $enabled): bool
    {
        /** @var Client $client */
        $client = Client::query()->findOrFail($clientId);

        $client->update(['auto_send_invoices' => $enabled]);

        return $client->auto_send_invoices;
    }
}
