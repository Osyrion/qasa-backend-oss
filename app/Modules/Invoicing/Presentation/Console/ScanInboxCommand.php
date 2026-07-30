<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Presentation\Console;

use App\Modules\Auth\Domain\Models\User;
use App\Modules\Invoicing\Application\Actions\ScanInboxAction;
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

    public function handle(ScanInboxAction $action): int
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
        // is what lets User::find() below see the row at all.
        $scanIfEnabled = function (string $accountId) use ($action, &$scanned, &$failed, &$skipped, &$failures): void {
            $account = User::query()->where('invoice_inbox_enabled', true)->find($accountId);

            if ($account === null) {
                return;
            }

            try {
                $counts = $action->execute($account);
                $scanned += $counts['scanned'];
                $failed += $counts['failed'];
                $skipped += $counts['skipped'];

                $this->line("Account {$account->id}: {$counts['scanned']} scanned, {$counts['failed']} failed, {$counts['skipped']} skipped.");
            } catch (Throwable $e) {
                $failures++;
                report($e);
                Log::error('Invoice inbox scan failed for an account', [
                    'account_id' => $account->id,
                    'exception' => $e->getMessage(),
                ]);
                $this->error("Account {$account->id} failed: {$e->getMessage()}");
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
