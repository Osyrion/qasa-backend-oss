<?php

declare(strict_types=1);

namespace App\Modules\Auth\Presentation\Console;

use App\Modules\Auth\Application\Actions\PurgeDeletedAccountsAction;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

class PurgeDeletedAccountsCommand extends Command
{
    protected $signature = 'qasa:accounts:purge
        {--date= : Treat this date as today (testing/backfill)}
        {--dry-run : Report what would be anonymised without writing}';

    protected $description = 'Anonymise accounts deleted longer ago than the configured grace period';

    public function handle(PurgeDeletedAccountsAction $action): int
    {
        /** @var string|null $dateOption */
        $dateOption = $this->option('date');
        $today = CarbonImmutable::parse($dateOption ?? 'today')->startOfDay();

        $dryRun = (bool) $this->option('dry-run');

        $count = $action->execute($today, $dryRun);

        $this->info($dryRun
            ? "Would anonymise {$count} account(s)."
            : "Anonymised {$count} account(s).");

        return self::SUCCESS;
    }
}
