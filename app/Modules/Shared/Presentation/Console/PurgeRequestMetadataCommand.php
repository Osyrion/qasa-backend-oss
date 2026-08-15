<?php

declare(strict_types=1);

namespace App\Modules\Shared\Presentation\Console;

use App\Modules\Shared\Application\Actions\PurgeRequestMetadataAction;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

class PurgeRequestMetadataCommand extends Command
{
    protected $signature = 'qasa:privacy:purge-request-metadata
        {--date= : Treat this date as today (testing/backfill)}';

    protected $description = 'Delete expired sessions and clear IP/user-agent metadata outside its retention window';

    public function handle(PurgeRequestMetadataAction $action): int
    {
        /** @var string|null $dateOption */
        $dateOption = $this->option('date');
        $now = CarbonImmutable::parse($dateOption ?? 'now');

        $affected = $action->execute($now);

        $this->info("Purged request metadata on {$affected} row(s).");

        return self::SUCCESS;
    }
}
