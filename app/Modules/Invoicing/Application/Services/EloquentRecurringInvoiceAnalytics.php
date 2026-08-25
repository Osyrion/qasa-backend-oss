<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Application\Services;

use App\Modules\Invoicing\Application\Contracts\RecurringInvoiceAnalytics;
use App\Modules\Invoicing\Domain\Enums\InvoiceType;
use App\Modules\Invoicing\Domain\Models\RecurringInvoiceTemplate;
use App\Modules\Invoicing\Domain\Models\RecurringInvoiceTemplateItem;
use App\Modules\Invoicing\Domain\ValueObjects\ProjectedInflow;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;

final class EloquentRecurringInvoiceAnalytics implements RecurringInvoiceAnalytics
{
    /**
     * Templates rarely fire more than once inside a short window (even
     * monthly is at most 2 occurrences in four weeks) — this only guards
     * against a misconfigured period ever looping indefinitely.
     */
    private const MAX_PROJECTED_OCCURRENCES = 8;

    public function projectedInflows(string $ownerId, string $from, string $to): array
    {
        $windowStart = Carbon::parse($from);
        $windowEnd = Carbon::parse($to);

        $templates = RecurringInvoiceTemplate::withoutGlobalScope('user')
            ->where('user_id', $ownerId)
            ->where('status', 'active')
            ->where('type', InvoiceType::Invoice->value)
            ->where('next_run_date', '<=', $windowEnd->toDateString())
            ->with('items')
            ->get();

        $inflows = [];

        foreach ($templates as $template) {
            $itemsTotal = $template->items->sum(
                fn (RecurringInvoiceTemplateItem $item): float => (float) $item->quantity * (float) $item->unit_price * (1 + (float) $item->vat_rate / 100)
            );

            $occurrence = $template->next_run_date;

            for ($i = 0; $i < self::MAX_PROJECTED_OCCURRENCES; $i++) {
                if ($template->end_date !== null && $occurrence->greaterThan($template->end_date)) {
                    break;
                }

                if ($occurrence->toDateString() > $windowEnd->toDateString()) {
                    break;
                }

                $dueDate = Carbon::parse($occurrence->toDateString())->addDays($template->due_days);

                if ($dueDate->greaterThanOrEqualTo($windowStart) && $dueDate->lessThanOrEqualTo($windowEnd)) {
                    $inflows[] = new ProjectedInflow(
                        currency: $template->currency,
                        dueAt: $dueDate,
                        amount: (float) $itemsTotal,
                    );
                }

                $occurrence = $this->nextOccurrence($template, $occurrence);
            }
        }

        return $inflows;
    }

    private function nextOccurrence(RecurringInvoiceTemplate $template, CarbonImmutable $from): CarbonImmutable
    {
        return $template->period->nextDate($from, $template->day_of_month, $template->last_day_of_month);
    }
}
