<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Application\Actions;

use App\Modules\Invoicing\Application\Contracts\GenerateInvoiceFromTemplateActionInterface;
use App\Modules\Invoicing\Application\DTOs\InvoiceData;
use App\Modules\Invoicing\Domain\Enums\RecurringTemplateStatus;
use App\Modules\Invoicing\Domain\Models\Invoice;
use App\Modules\Invoicing\Domain\Models\RecurringInvoiceTemplate;
use App\Modules\Invoicing\Domain\Models\RecurringInvoiceTemplateItem;
use App\Modules\Invoicing\Domain\Models\VatRate;
use App\Modules\Invoicing\Domain\Services\PeriodPlaceholderResolver;
use App\Modules\Shared\Exceptions\DomainException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Generates one scheduled draft invoice from a recurring template and
 * advances the schedule. Invoice, items and the schedule advance commit in
 * a single transaction — that atomicity is the idempotency guarantee (a
 * second run the same day finds nothing due).
 */
readonly class GenerateInvoiceFromTemplateAction implements GenerateInvoiceFromTemplateActionInterface
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
                // Intent only on the template — the actual mode is re-resolved
                // against the current client via TaxSystem::vatRegimeResolver().
                reverse_charge: $template->reverse_charge,
            ), $owner);

            $invoice->update([
                'recurring_template_id' => $template->id,
            ]);

            // A template can outlive a catalog change (rate removed/expired
            // since the template was created) — re-check on every
            // generation, not just at template create/update time.
            if (! $invoice->reverse_charge) {
                foreach ($template->items as $item) {
                    $this->assertRateInCatalog($owner->accountOwnerId(), (string) $owner->supplierProfile()->country, $item, $issuedAt->toDateString());
                }
            }

            foreach ($template->items as $item) {
                $invoice->items()
                    ->make([
                        'description' => $this->placeholders->resolve($item->description, $periodDate),
                        'quantity' => $item->quantity,
                        'unit' => $item->unit,
                        'unit_price' => $item->unit_price,
                        'vat_rate' => $invoice->reverse_charge ? 0 : $item->vat_rate,
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

    /**
     * @throws DomainException
     */
    private function assertRateInCatalog(string $userId, string $country, RecurringInvoiceTemplateItem $item, string $onDate): void
    {
        $rate = (float) $item->vat_rate;

        if ($rate <= 0.0) {
            return;
        }

        $date = CarbonImmutable::parse($onDate);
        $country = strtoupper($country);

        $exists = VatRate::withoutGlobalScope('user')
            ->where('user_id', $userId)
            ->where('country', $country)
            ->get()
            ->contains(fn (VatRate $vatRate): bool => (float) $vatRate->rate === $rate && $vatRate->isValidOn($date));

        if (! $exists) {
            throw DomainException::because(__('invoicing.template_item_vat_rate_not_in_catalog', [
                'description' => $item->description,
                'rate' => $item->vat_rate,
            ]));
        }
    }
}
