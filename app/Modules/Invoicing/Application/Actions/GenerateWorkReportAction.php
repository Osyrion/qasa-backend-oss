<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Application\Actions;

use App\Modules\Invoicing\Application\Contracts\TrackedWorkDates;
use App\Modules\Invoicing\Domain\Models\Invoice;
use App\Modules\Invoicing\Domain\Models\InvoiceItem;
use App\Modules\Invoicing\Domain\Models\InvoiceWorkReportLine;
use App\Modules\Shared\Exceptions\DomainException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Prefills the "Výkaz víceprací" from the tracked work behind the invoice
 * items. Replaces any existing lines; the user can edit them afterwards while
 * the invoice is a draft.
 *
 * Work reports are an OSS feature — lines can always be entered by hand
 * through SyncWorkReportLinesAction. Only the *dates* need a module that
 * tracks work, and in the core that module does not exist: TrackedWorkDates
 * answers nothing, no line matches, and the hand-entered ones are returned
 * untouched.
 */
final readonly class GenerateWorkReportAction
{
    public function __construct(
        private TrackedWorkDates $trackedWork,
    ) {}

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

        $items = $invoice->items()->whereNotNull('time_entry_id')->get();

        /** @var list<string> $workIds */
        $workIds = array_values(array_unique(array_map(
            static fn (mixed $id): string => (string) $id,
            $items->pluck('time_entry_id')->all(),
        )));

        $dates = $this->trackedWork->datesFor($workIds);

        if ($dates === []) {
            // Nothing to prefill from — leave what is there. Deleting the
            // hand-entered lines to replace them with none would lose work.
            return $invoice->workReportLines()->get();
        }

        return DB::transaction(function () use ($invoice, $items, $dates): Collection {
            $invoice->workReportLines()->delete();

            $ordered = $items
                ->filter(fn (InvoiceItem $item): bool => isset($dates[(string) $item->time_entry_id]))
                ->sortBy(fn (InvoiceItem $item) => $dates[(string) $item->time_entry_id]);

            $sort = 0;

            foreach ($ordered as $item) {
                $invoice->workReportLines()->create([
                    'time_entry_id' => $item->time_entry_id,
                    'work_date' => $dates[(string) $item->time_entry_id]->toDateString(),
                    'description' => $item->description,
                    'hours' => (float) $item->quantity,
                    'sort_order' => $sort++,
                ]);
            }

            return $invoice->workReportLines()->get();
        });
    }
}
