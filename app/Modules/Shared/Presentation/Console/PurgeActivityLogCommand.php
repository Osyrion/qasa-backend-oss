<?php

declare(strict_types=1);

namespace App\Modules\Shared\Presentation\Console;

use App\Modules\Shared\Application\Actions\PurgeActivityLogAction;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

class PurgeActivityLogCommand extends Command
{
    protected $signature = 'qasa:activity:purge
        {--date= : Treat this date as today (testing/backfill)}';

    protected $description = 'Delete activity log entries outside the configured retention window';

    public function handle(PurgeActivityLogAction $action): int
    {
        /** @var string|null $dateOption */
        $dateOption = $this->option('date');
        $today = CarbonImmutable::parse($dateOption ?? 'today')->startOfDay();

        $deleted = $action->execute($today);

        $this->info("Purged {$deleted} activity log entry(s).");

        return self::SUCCESS;
    }
}
