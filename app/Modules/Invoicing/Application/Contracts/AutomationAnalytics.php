<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Application\Contracts;

use App\Modules\Invoicing\Domain\Enums\AutomationKind;
use App\Modules\Invoicing\Domain\ValueObjects\AutomationTally;

/**
 * How much of an account's document work happened without anyone doing it —
 * the metric AUTOMATION_FIRST_ROADMAP_PLAN.md defines as "% of documents this
 * period that needed no manual intervention".
 */
interface AutomationAnalytics
{
    /**
     * One tally per {@see AutomationKind},
     * always all of them, so a caller computing a percentage over the whole
     * period does not have to know which kinds exist.
     *
     * Bounds are datetimes rather than dates: the period is compared against
     * created_at and emailed_at, and truncating the upper bound to midnight
     * would drop the last day.
     *
     * @return list<AutomationTally>
     */
    public function tally(string $ownerId, string $from, string $to): array;
}
