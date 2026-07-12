<?php

declare(strict_types=1);

namespace App\Modules\Integrations\Application\Actions;

use App\Modules\Integrations\Application\DTOs\WebhookEndpointData;
use App\Modules\Integrations\Domain\Models\WebhookEndpoint;

readonly class UpdateWebhookEndpointAction
{
    public function execute(WebhookEndpoint $endpoint, WebhookEndpointData $data): WebhookEndpoint
    {
        $endpoint->update([
            'url' => $data->url,
            'events' => $data->events,
            'is_active' => $data->is_active,
        ]);

        return $endpoint->refresh();
    }
}
