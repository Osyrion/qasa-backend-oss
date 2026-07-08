<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Application\Actions;

use App\Modules\Invoicing\Domain\Models\Invoice;
use App\Modules\Invoicing\Domain\Models\InvoiceWorkReportLine;
use App\Modules\Shared\Exceptions\DomainException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Prefills the "Výkaz víceprací" from the time entries behind the invoice
 * items. Replaces any existing lines; the user can edit them afterwards
 * while the invoice is a draft.
 */
readonly class GenerateWorkReportAction
{
    /**
     * @return Collection<int, InvoiceWorkReportLine>
     *
     * @throws DomainException
     * @throws Throwable
     */
    public function execute(Invoice $invoice): Collection
    {
        if (! $invoice->isEditable()) {
            throw DomainException::because(__('invoicing.work_report_only_generatable_for_draft'));
        }

        return DB::transaction(function () use ($invoice): Collection {
            $invoice->workReportLines()->delete();

            $items = $invoice->items()
                ->whereNotNull('time_entry_id')
                ->with('timeEntry')
                ->get()
                ->sortBy(fn ($item) => $item->timeEntry?->started_at);

            $sort = 0;

            foreach ($items as $item) {
                $entry = $item->timeEntry;

                if ($entry === null) {
                    continue;
                }

                $invoice->workReportLines()->create([
                    'time_entry_id' => $entry->id,
                    'work_date' => $entry->started_at->toDateString(),
                    'description' => $item->description,
                    'hours' => (float) $item->quantity,
                    'sort_order' => $sort++,
                ]);
            }

            return $invoice->workReportLines()->get();
        });
    }
}
