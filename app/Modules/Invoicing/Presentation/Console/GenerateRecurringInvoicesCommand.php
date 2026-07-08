<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Presentation\Console;

use App\Modules\Invoicing\Application\Actions\GenerateInvoiceFromTemplateAction;
use App\Modules\Invoicing\Application\Actions\SendInvoiceEmailAction;
use App\Modules\Invoicing\Application\Contracts\RecurringInvoiceTemplateRepositoryInterface;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

class GenerateRecurringInvoicesCommand extends Command
{
    /**
     * Safety cap on catch-up iterations per template — a date-math bug must
     * not turn into an infinite loop (24 monthly periods = two years).
     */
    private const MAX_ITERATIONS_PER_TEMPLATE = 24;

    protected $signature = 'qasa:invoices:generate-recurring
        {--date= : Treat this date as today (testing/backfill)}';

    protected $description = 'Generate draft invoices from recurring templates that are due';

    public function handle(
        RecurringInvoiceTemplateRepositoryInterface $repository,
        GenerateInvoiceFromTemplateAction $action,
        SendInvoiceEmailAction $sendEmailAction,
    ): int {
        /** @var string|null $dateOption */
        $dateOption = $this->option('date');
        $today = CarbonImmutable::parse($dateOption ?? 'today')->startOfDay();

        $generated = 0;
        $expired = 0;
        $failures = 0;
        $emailFailures = 0;

        foreach ($repository->dueForGeneration($today) as $template) {
            try {
                // Catch-up: one invoice per missed period, drained in one run.
                $iterations = 0;
                $lastGenerated = null;

                while (
                    $template->refresh()->isActive()
                    && $template->next_run_date->lessThanOrEqualTo($today)
                    && $iterations < self::MAX_ITERATIONS_PER_TEMPLATE
                ) {
                    $lastGenerated = $action->execute($template);
                    $generated++;
                    $iterations++;

                    $this->line("Template {$template->id} ({$template->name}): generated {$lastGenerated->invoice_number}.");
                }

                if (
                    $iterations >= self::MAX_ITERATIONS_PER_TEMPLATE
                    && $template->refresh()->isActive()
                    && $template->next_run_date->lessThanOrEqualTo($today)
                ) {
                    Log::warning('Recurring template hit the catch-up iteration cap', [
                        'template_id' => $template->id,
                        'next_run_date' => $template->next_run_date->toDateString(),
                    ]);
                    $this->warn("Template {$template->id} ({$template->name}) hit the catch-up cap of ".self::MAX_ITERATIONS_PER_TEMPLATE.' invoices and is still behind; the next run will continue.');
                }

                // Auto-send runs after the generation transactions committed and
                // covers only the newest invoice — a cron outage must not flood
                // the client; caught-up periods stay drafts for manual review.
                // A send failure leaves the invoice as draft and doesn't count
                // as a generation failure.
                if ($template->auto_send && $lastGenerated !== null) {
                    try {
                        $sendEmailAction->execute($lastGenerated);
                        $this->line("Template {$template->id} ({$template->name}): {$lastGenerated->invoice_number} issued and queued for email.");
                    } catch (Throwable $e) {
                        $emailFailures++;
                        report($e);
                        Log::error('Recurring invoice auto-send failed', [
                            'template_id' => $template->id,
                            'invoice_id' => $lastGenerated->id,
                            'exception' => $e->getMessage(),
                        ]);
                        $this->error("Template {$template->id} ({$template->name}): {$lastGenerated->invoice_number} generated but email failed: {$e->getMessage()}");
                    }
                }

                if ($template->isExpired()) {
                    $expired++;
                    $this->line("Template {$template->id} ({$template->name}) expired.");
                }
            } catch (Throwable $e) {
                $failures++;
                report($e);
                Log::error('Recurring invoice generation failed', [
                    'template_id' => $template->id,
                    'exception' => $e->getMessage(),
                ]);
                $this->error("Template {$template->id} ({$template->name}) failed: {$e->getMessage()}");
            }
        }

        $this->info("Done: {$generated} invoices generated, {$expired} templates expired, {$failures} failures, {$emailFailures} email failures.");

        // Email failures leave correctly generated draft invoices behind,
        // so only generation failures flip the exit code.
        return $failures > 0 ? self::FAILURE : self::SUCCESS;
    }
}
