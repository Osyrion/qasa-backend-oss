<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Application\Actions;

use App\Modules\Invoicing\Domain\Enums\RecurringTemplateStatus;
use App\Modules\Invoicing\Domain\Models\RecurringInvoiceTemplate;
use App\Modules\Shared\Exceptions\DomainException;

readonly class PauseRecurringTemplateAction
{
    public function execute(RecurringInvoiceTemplate $template): RecurringInvoiceTemplate
    {
        if (! $template->isActive()) {
            throw DomainException::invalidState(__('invoicing.template_not_active'));
        }

        $template->status = RecurringTemplateStatus::Paused;
        $template->save();

        return $template;
    }
}
