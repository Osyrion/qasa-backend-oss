<?php

declare(strict_types=1);

namespace App\Modules\Shared\Presentation\Console;

use App\Modules\Shared\Application\Actions\PurgeIdempotencyKeysAction;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

class PurgeIdempotencyKeysCommand extends Command
{
    protected $signature = 'qasa:idempotency-keys:purge
        {--date= : Treat this date/time as now (testing/backfill)}';

    protected $description = 'Delete idempotency key records past their 24h TTL';

    public function handle(PurgeIdempotencyKeysAction $action): int
    {
        /** @var string|null $dateOption */
        $dateOption = $this->option('date');
        $now = CarbonImmutable::parse($dateOption ?? 'now');

        $deleted = $action->execute($now);

        $this->info("Purged {$deleted} idempotency key record(s).");

        return self::SUCCESS;
    }
}
