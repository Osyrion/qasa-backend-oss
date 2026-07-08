<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Application\Actions;

use App\Modules\Invoicing\Application\DTOs\InvoiceData;
use App\Modules\Invoicing\Domain\Enums\RecurringTemplateStatus;
use App\Modules\Invoicing\Domain\Models\Invoice;
use App\Modules\Invoicing\Domain\Models\RecurringInvoiceTemplate;
use App\Modules\Invoicing\Domain\Services\PeriodPlaceholderResolver;
use App\Modules\Shared\Exceptions\DomainException;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Generates one scheduled draft invoice from a recurring template and
 * advances the schedule. Invoice, items and the schedule advance commit in
 * a single transaction — that atomicity is the idempotency guarantee (a
 * second run the same day finds nothing due).
 */
readonly class GenerateInvoiceFromTemplateAction
{
    public function __construct(
        private CreateInvoiceAction $createInvoice,
        private PeriodPlaceholderResolver $placeholders,
    ) {}

    /**
     * @throws Throwable
     */
    public function execute(RecurringInvoiceTemplate $template): Invoice
    {
        $owner = $template->user;

        if ($owner === null) {
            throw DomainException::invalidState(__('invoicing.template_missing_owner', ['id' => $template->id]));
        }

        // Soft-deleted client resolves to null through the default relation.
        if ($template->client === null) {
            $template->status = RecurringTemplateStatus::Paused;
            $template->save();

            throw DomainException::invalidState(
                __('invoicing.template_paused_missing_client', ['id' => $template->id]),
            );
        }

        return DB::transaction(function () use ($template, $owner): Invoice {
            // The scheduled date, not today — a late cron run still issues
            // the invoice dated as originally intended.
            $issuedAt = $template->next_run_date;
            $dueAt = $issuedAt->addDays($template->due_days);

            $taxableSupplyAt = $template->type->isTaxDocument()
                ? $template->tax_date_mode->resolve($issuedAt)
                : null;

            // Placeholders resolve against DUZP; proforma against issue date.
            $periodDate = $taxableSupplyAt ?? $issuedAt;

            $invoice = $this->createInvoice->execute(new InvoiceData(
                client_id: $template->client_id,
                issued_at: $issuedAt->toDateString(),
                due_at: $dueAt->toDateString(),
                currency: $template->currency,
                type: $template->type,
                taxable_supply_at: $taxableSupplyAt?->toDateString(),
                discount_percent: $template->discount_percent !== null ? (float) $template->discount_percent : null,
                note: $this->placeholders->resolve($template->note_below, $periodDate),
                note_above: $this->placeholders->resolve($template->note_above, $periodDate),
            ), $owner);

            $invoice->update([
                'recurring_template_id' => $template->id,
            ]);

            foreach ($template->items as $item) {
                $invoice->items()
                    ->make([
                        'description' => $this->placeholders->resolve($item->description, $periodDate),
                        'quantity' => $item->quantity,
                        'unit' => $item->unit,
                        'unit_price' => $item->unit_price,
                        'vat_rate' => $item->vat_rate,
                        'sort_order' => $item->sort_order,
                    ])
                    ->recalculate()
                    ->save();
            }

            $invoice->load('items');
            $invoice->recalculateTotals()->save();

            $template->last_generated_at = $issuedAt;
            $template->advanceSchedule();
            $template->save();

            return $invoice;
        });
    }
}
