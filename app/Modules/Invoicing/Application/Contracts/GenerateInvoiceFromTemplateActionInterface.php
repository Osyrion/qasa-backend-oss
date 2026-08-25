<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Application\Contracts;

use App\Modules\Invoicing\Domain\Models\Invoice;
use App\Modules\Invoicing\Domain\Models\RecurringInvoiceTemplate;

/**
 * Generating the next invoice from a recurring template.
 *
 * Published for the scheduled command in Automation, which owns *when* the
 * run happens — the same split InvoiceReminderRunner makes for reminders,
 * though not yet as cleanly: the template still crosses the boundary, because
 * the command drives the catch-up loop off it (`isActive()`, `next_run_date`)
 * and RecurringInvoiceTemplateRepositoryInterface already hands it over.
 * Folding that loop in here the way the reminder run was folded in is the
 * next step.
 */
interface GenerateInvoiceFromTemplateActionInterface
{
    public function execute(RecurringInvoiceTemplate $template): Invoice;
}
