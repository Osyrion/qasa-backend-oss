<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Application\Contracts;

use Illuminate\Support\Carbon;

/**
 * When each piece of tracked work was done.
 *
 * The only thing a work report needs from the module that tracks it. The
 * report's own lines — their wording, their hours, their order — come off the
 * invoice items that bill them, and writing them is Invoicing's: the previous
 * arrangement handed the whole `Invoice` to TimeTracking and let it delete and
 * recreate rows in `invoice_work_report_lines`, which is our table.
 *
 * OSS binds NoTrackedWorkDates: nothing tracks work there, so
 * `invoice_items.time_entry_id` is always null and there is nothing to date.
 */
interface TrackedWorkDates
{
    /**
     * @param  list<string>  $workIds
     * @return array<string, Carbon> keyed by work id; ids that no longer exist are absent
     */
    public function datesFor(array $workIds): array;
}
