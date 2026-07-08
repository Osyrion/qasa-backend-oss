<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Application\Actions;

use App\Modules\Invoicing\Application\DTOs\WorkReportLineData;
use App\Modules\Invoicing\Domain\Models\Invoice;
use App\Modules\Invoicing\Domain\Models\InvoiceWorkReportLine;
use App\Modules\Shared\Exceptions\DomainException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Bulk-replaces the work report lines (the editor's save contract).
 */
readonly class SyncWorkReportLinesAction
{
    /**
     * @param  list<WorkReportLineData>  $lines
     * @return Collection<int, InvoiceWorkReportLine>
     *
     * @throws DomainException
     * @throws Throwable
     */
    public function execute(Invoice $invoice, array $lines): Collection
    {
        if (! $invoice->isEditable()) {
            throw DomainException::because(__('invoicing.work_report_only_editable_for_draft'));
        }

        return DB::transaction(function () use ($invoice, $lines): Collection {
            $invoice->workReportLines()->delete();

            foreach ($lines as $sort => $line) {
                $invoice->workReportLines()->create([
                    'time_entry_id' => $line->time_entry_id,
                    'work_date' => $line->work_date,
                    'description' => $line->description,
                    'hours' => $line->hours,
                    'sort_order' => $sort,
                ]);
            }

            return $invoice->workReportLines()->get();
        });
    }
}
