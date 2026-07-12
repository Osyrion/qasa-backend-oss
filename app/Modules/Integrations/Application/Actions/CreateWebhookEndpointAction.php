<?php

declare(strict_types=1);

namespace App\Modules\Integrations\Application\Actions;

use App\Modules\Integrations\Application\DTOs\WebhookEndpointData;
use App\Modules\Integrations\Domain\Models\WebhookEndpoint;
use Illuminate\Support\Str;

readonly class CreateWebhookEndpointAction
{
    /**
     * @return array{endpoint: WebhookEndpoint, secret: string}
     */
    public function execute(WebhookEndpointData $data, string $userId): array
    {
        $secret = Str::random(40);

        $endpoint = WebhookEndpoint::create([
            'user_id' => $userId,
            'url' => $data->url,
            'secret' => $secret,
            'events' => $data->events,
            'is_active' => $data->is_active,
        ]);

        return ['endpoint' => $endpoint, 'secret' => $secret];
    }
}
