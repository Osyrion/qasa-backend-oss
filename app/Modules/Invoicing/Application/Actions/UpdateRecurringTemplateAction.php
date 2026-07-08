<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Application\Actions;

use App\Modules\Invoicing\Application\DTOs\RecurringTemplateData;
use App\Modules\Invoicing\Domain\Enums\RecurringTemplateStatus;
use App\Modules\Invoicing\Domain\Models\RecurringInvoiceTemplate;
use Illuminate\Support\Facades\DB;
use Throwable;

readonly class UpdateRecurringTemplateAction
{
    /**
     * @throws Throwable
     */
    public function execute(RecurringInvoiceTemplate $template, RecurringTemplateData $data): RecurringInvoiceTemplate
    {
        return DB::transaction(function () use ($template, $data): RecurringInvoiceTemplate {
            $scheduleChanged = $template->period !== $data->period
                || $template->day_of_month !== $data->day_of_month
                || $template->last_day_of_month !== $data->last_day_of_month;

            $template->fill([
                'client_id' => $data->client_id,
                'name' => $data->name,
                'period' => $data->period->value,
                'day_of_month' => $data->day_of_month,
                'last_day_of_month' => $data->last_day_of_month,
                'first_issue_date' => $data->first_issue_date,
                'end_date' => $data->end_date,
                'type' => $data->type->value,
                'currency' => $data->currency->value,
                'due_days' => $data->due_days,
                'discount_percent' => $data->discount_percent,
                'tax_date_mode' => $data->tax_date_mode->value,
                'auto_send' => $data->auto_send,
                'note_above' => $data->note_above,
                'note_below' => $data->note_below,
            ]);

            // Never generated: the schedule simply follows first_issue_date.
            // Generated already: recompute the next occurrence from the last
            // one only when the periodicity settings actually changed.
            if ($template->last_generated_at === null) {
                $template->next_run_date = $template->first_issue_date;
            } elseif ($scheduleChanged) {
                $template->next_run_date = $data->period->nextDate(
                    $template->last_generated_at,
                    $data->day_of_month,
                    $data->last_day_of_month,
                );
            }

            // end_date moved in either direction: expired <-> active.
            if ($template->end_date !== null && $template->next_run_date->greaterThan($template->end_date)) {
                $template->status = RecurringTemplateStatus::Expired;
            } elseif ($template->isExpired()) {
                $template->status = RecurringTemplateStatus::Active;
            }

            $template->save();

            $template->items()->delete();
            foreach ($data->items as $index => $item) {
                $template->items()->create([
                    'description' => $item->description,
                    'quantity' => $item->quantity,
                    'unit' => $item->unit,
                    'unit_price' => $item->unit_price,
                    'vat_rate' => $item->vat_rate,
                    'sort_order' => $item->sort_order !== 0 ? $item->sort_order : $index,
                ]);
            }

            return $template->load('items');
        });
    }
}
