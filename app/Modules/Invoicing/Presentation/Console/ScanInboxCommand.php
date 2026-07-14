<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Presentation\Console;

use App\Modules\Auth\Domain\Models\User;
use App\Modules\Invoicing\Application\Actions\ScanInboxAction;
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

        $accounts = User::query()->where('invoice_inbox_enabled', true)
            ->when($accountOption !== null, fn ($query) => $query->where('id', $accountOption))
            ->get();

        $scanned = 0;
        $failed = 0;
        $skipped = 0;
        $failures = 0;

        foreach ($accounts as $account) {
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
        }

        $this->info("Done: {$scanned} scanned, {$failed} failed extractions, {$skipped} skipped, {$failures} account failures.");

        return $failures > 0 ? self::FAILURE : self::SUCCESS;
    }
}
