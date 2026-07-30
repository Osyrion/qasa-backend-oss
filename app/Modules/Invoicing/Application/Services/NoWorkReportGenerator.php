<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Application\Services;

use App\Modules\Invoicing\Application\Contracts\WorkReportGeneratorInterface;
use App\Modules\Invoicing\Domain\Models\Invoice;
use App\Modules\Invoicing\Domain\Models\InvoiceWorkReportLine;
use App\Modules\Shared\Exceptions\DomainException;
use Illuminate\Database\Eloquent\Collection;

/**
 * OSS core default: there is no tracked work to prefill from, so generating
 * leaves the invoice's existing (hand-entered) lines untouched and returns
 * them as they are.
 */
final class NoWorkReportGenerator implements WorkReportGeneratorInterface
{
    /**
     * @return Collection<int, InvoiceWorkReportLine>
     *
     * @throws DomainException
     */
    public function generate(Invoice $invoice): Collection
    {
        if (! $invoice->isEditable()) {
            throw DomainException::because(__('invoicing.work_report_only_generatable_for_draft'));
        }

        return $invoice->workReportLines()->get();
    }
}
