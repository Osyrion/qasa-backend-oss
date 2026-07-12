<?php

declare(strict_types=1);

namespace App\Modules\Integrations\Presentation\Console;

use App\Modules\Integrations\Application\Actions\PurgeWebhookDeliveriesAction;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

class PurgeWebhookDeliveriesCommand extends Command
{
    protected $signature = 'qasa:integrations:purge-webhook-deliveries
        {--date= : Treat this date as today (testing/backfill)}';

    protected $description = 'Delete webhook delivery attempt logs outside the configured retention window';

    public function handle(PurgeWebhookDeliveriesAction $action): int
    {
        /** @var string|null $dateOption */
        $dateOption = $this->option('date');
        $today = CarbonImmutable::parse($dateOption ?? 'today')->startOfDay();

        $deleted = $action->execute($today);

        $this->info("Purged {$deleted} webhook delivery log(s).");

        return self::SUCCESS;
    }
}
