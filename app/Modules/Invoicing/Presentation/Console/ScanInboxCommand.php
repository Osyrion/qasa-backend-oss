<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Presentation\Console;

use App\Modules\Invoicing\Application\Actions\ScanInboxAction;
use App\Modules\Shared\Domain\Contracts\AccountLocator;
use App\Modules\Shared\Support\AccountLookup;
use App\Modules\Shared\Support\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

class ScanInboxCommand extends Command
{
    protected $signature = 'qasa:invoices:scan-inbox
        {--account= : Only scan this account (user id)}';

    protected $description = 'Scan each account\'s invoice inbox folder and stage new documents for review';

    public function handle(ScanInboxAction $action, AccountLocator $accounts): int
    {
        /** @var string|null $accountOption */
        $accountOption = $this->option('account');

        $scanned = 0;
        $failed = 0;
        $skipped = 0;
        $failures = 0;

        // users is tenant-scoped too now (phase 7), so "enabled" itself has
        // to be checked after binding: forEachAccount() supplies the
        // account ids via a definer function and binds each in turn, which
        // is what lets the lookup below see the row at all.
        $scanIfEnabled = function (string $accountId) use ($action, $accounts, &$scanned, &$failed, &$skipped, &$failures): void {
            $account = $accounts->find($accountId);

            if ($account === null || ! $account->invoiceInboxEnabled()) {
                return;
            }

            try {
                $counts = $action->execute($account);
                $scanned += $counts['scanned'];
                $failed += $counts['failed'];
                $skipped += $counts['skipped'];

                $this->line("Account {$account->accountOwnerId()}: {$counts['scanned']} scanned, {$counts['failed']} failed, {$counts['skipped']} skipped.");
            } catch (Throwable $e) {
                $failures++;
                report($e);
                Log::error('Invoice inbox scan failed for an account', [
                    'account_id' => $account->accountOwnerId(),
                    'exception' => $e->getMessage(),
                ]);
                $this->error("Account {$account->accountOwnerId()} failed: {$e->getMessage()}");
            }
        };

        if ($accountOption !== null) {
            // --account may name a team member, not just the owner —
            // resolve to whichever account it belongs to, same as every
            // other id-in-hand lookup does.
            AccountLookup::bindById($accountOption);
            $bound = TenantContext::current();

            if ($bound !== null) {
                TenantContext::for($bound, fn () => $scanIfEnabled($bound));
            }
        } else {
            TenantContext::forEachAccount($scanIfEnabled);
        }

        $this->info("Done: {$scanned} scanned, {$failed} failed extractions, {$skipped} skipped, {$failures} account failures.");

        return $failures > 0 ? self::FAILURE : self::SUCCESS;
    }
}
