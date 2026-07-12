<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Presentation\Console;

use App\Modules\Auth\Domain\Models\User;
use App\Modules\Invoicing\Application\Services\VatRateSeederService;
use Illuminate\Console\Command;

class BackfillVatRatesCommand extends Command
{
    protected $signature = 'qasa:invoices:backfill-vat-rates';

    protected $description = 'Seed the VAT rate catalog for any account missing its country\'s configured rates';

    public function handle(VatRateSeederService $seeder): int
    {
        $count = 0;

        User::query()->whereNull('deleted_at')->each(function (User $user) use ($seeder, &$count): void {
            $seeder->seedFor($user);
            $count++;
        });

        $this->info("Checked VAT rate catalog for {$count} account(s).");

        return self::SUCCESS;
    }
}
