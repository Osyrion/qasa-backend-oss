<?php

declare(strict_types=1);

namespace App\Modules\Integrations\Application\Actions;

use App\Modules\Integrations\Domain\Models\WebhookDelivery;
use Carbon\CarbonImmutable;

final readonly class PurgeWebhookDeliveriesAction
{
    public function execute(CarbonImmutable $today): int
    {
        $retentionDays = (int) config('integrations.webhook_delivery_retention_days', 14);
        $cutoff = $today->subDays($retentionDays);

        return WebhookDelivery::where('created_at', '<', $cutoff)->delete();
    }
}
