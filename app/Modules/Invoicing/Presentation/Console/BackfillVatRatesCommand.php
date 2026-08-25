<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Presentation\Console;

use App\Modules\Invoicing\Application\Services\VatRateSeederService;
use App\Modules\Shared\Domain\Contracts\AccountLocator;
use App\Modules\Shared\Support\TenantContext;
use Illuminate\Console\Command;

class BackfillVatRatesCommand extends Command
{
    protected $signature = 'qasa:invoices:backfill-vat-rates';

    protected $description = "Seed the VAT rate catalog for any account missing its country's configured rates";

    public function handle(VatRateSeederService $seeder, AccountLocator $accounts): int
    {
        $count = 0;

        // Bound per account: the rate catalog is behind a row-level policy,
        // so both the "is it already there" read and the seed write only
        // reach the account the connection is bound to. users is
        // tenant-scoped too now (phase 7), so the account list itself has
        // to come from forEachAccount() — the lookup below only sees a
        // soft-deleted account's row if withTrashed() asks for it, same as
        // the whereNull('deleted_at') this replaces.
        TenantContext::forEachAccount(function (string $accountId) use ($seeder, $accounts, &$count): void {
            $user = $accounts->find($accountId);

            if ($user === null) {
                return;
            }

            $seeder->seedFor($user);
            $count++;
        });

        $this->info("Checked VAT rate catalog for {$count} account(s).");

        return self::SUCCESS;
    }
}
