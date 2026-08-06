<?php

declare(strict_types=1);

namespace App\Modules\Shared\Presentation\Console;

use App\Modules\Shared\Application\Actions\PurgeNotificationsAction;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

class PurgeNotificationsCommand extends Command
{
    protected $signature = 'qasa:notifications:purge
        {--date= : Treat this date as today (testing/backfill)}';

    protected $description = 'Delete read in-app notifications outside the configured retention window';

    public function handle(PurgeNotificationsAction $action): int
    {
        /** @var string|null $dateOption */
        $dateOption = $this->option('date');
        $today = CarbonImmutable::parse($dateOption ?? 'today')->startOfDay();

        $deleted = $action->execute($today);

        $this->info("Purged {$deleted} notification(s).");

        return self::SUCCESS;
    }
}
