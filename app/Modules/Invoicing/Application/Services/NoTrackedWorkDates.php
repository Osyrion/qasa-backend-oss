<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Application\Services;

use App\Modules\Invoicing\Application\Contracts\TrackedWorkDates;

/**
 * OSS core default: nothing tracks work, so no invoice line is billed from any
 * and there is nothing to prefill a report from. Lines entered by hand through
 * SyncWorkReportLinesAction are untouched — see GenerateWorkReportAction.
 */
final class NoTrackedWorkDates implements TrackedWorkDates
{
    public function datesFor(array $workIds): array
    {
        return [];
    }
}
