<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Application\Contracts;

use App\Modules\Invoicing\Domain\Models\Invoice;
use App\Modules\Invoicing\Domain\Models\InvoiceWorkReportLine;
use Illuminate\Database\Eloquent\Collection;

/**
 * Prefills an invoice's work report from tracked work.
 *
 * Work reports themselves are an OSS feature — lines can always be entered by
 * hand through SyncWorkReportLinesAction. Only the prefill needs tracked work,
 * so OSS binds NoWorkReportGenerator (nothing to prefill from) and
 * TimeTracking rebinds it to the TimeEntry-backed generator.
 */
interface WorkReportGeneratorInterface
{
    /**
     * @return Collection<int, InvoiceWorkReportLine>
     */
    public function generate(Invoice $invoice): Collection;
}
