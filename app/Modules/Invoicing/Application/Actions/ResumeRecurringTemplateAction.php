<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Application\Actions;

use App\Modules\Invoicing\Domain\Enums\RecurringTemplateStatus;
use App\Modules\Invoicing\Domain\Models\RecurringInvoiceTemplate;
use App\Modules\Shared\Exceptions\DomainException;
use Carbon\CarbonImmutable;

readonly class ResumeRecurringTemplateAction
{
    public function execute(RecurringInvoiceTemplate $template): RecurringInvoiceTemplate
    {
        if (! $template->isPaused()) {
            throw DomainException::invalidState(__('invoicing.template_not_paused'));
        }

        $template->status = RecurringTemplateStatus::Active;

        // Pausing means the covered periods are skipped — fast-forward past
        // them so resuming never dumps a backlog of drafts.
        $today = CarbonImmutable::today();
        while ($template->isActive() && $template->next_run_date->lessThan($today)) {
            $template->advanceSchedule();
        }

        $template->save();

        return $template;
    }
}
