<?php

declare(strict_types=1);

namespace App\Modules\Calendar\Presentation\Console;

use App\Modules\Calendar\Application\Actions\PurgePastEventsAction;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

class PurgePastEventsCommand extends Command
{
    protected $signature = 'qasa:calendar:purge-past
        {--date= : Treat this date as today (testing/backfill)}';

    protected $description = 'Force-delete events outside the configured retention window';

    public function handle(PurgePastEventsAction $action): int
    {
        /** @var string|null $dateOption */
        $dateOption = $this->option('date');
        $today = CarbonImmutable::parse($dateOption ?? 'today')->startOfDay();

        $deleted = $action->execute($today);

        $this->info("Purged {$deleted} past event(s).");

        return self::SUCCESS;
    }
}
