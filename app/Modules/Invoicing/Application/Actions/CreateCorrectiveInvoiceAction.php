<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Application\Actions;

use App\Modules\Auth\Domain\Models\User;
use App\Modules\Invoicing\Application\Contracts\InvoiceRepositoryInterface;
use App\Modules\Invoicing\Domain\Enums\InvoiceStatus;
use App\Modules\Invoicing\Domain\Enums\InvoiceType;
use App\Modules\Invoicing\Domain\Events\InvoiceCreated;
use App\Modules\Invoicing\Domain\Models\Invoice;
use App\Modules\Shared\Exceptions\DomainException;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Creates a dobropis (credit note) or storno document from an issued invoice:
 * a new draft with negated quantities referencing the original.
 * Storno additionally cancels the original invoice.
 */
readonly class CreateCorrectiveInvoiceAction
{
    public function __construct(
        private InvoiceRepositoryInterface $repository,
        private UpdateInvoiceStatusAction $updateStatusAction,
    ) {}

    /**
     * @throws DomainException
     * @throws Throwable
     */
    public function execute(Invoice $original, InvoiceType $type, User $user): Invoice
    {
        if (! $type->isCorrective()) {
            throw DomainException::because(__('invoicing.corrective_must_be_credit_or_storno'));
        }

        if ($original->type !== InvoiceType::Invoice) {
            throw DomainException::because(__('invoicing.corrective_only_for_invoice'));
        }

        $allowedStatuses = $type === InvoiceType::Storno
            ? [InvoiceStatus::Sent]
            : [InvoiceStatus::Sent, InvoiceStatus::Paid];

        if (! in_array($original->status, $allowedStatuses, true)) {
            throw DomainException::because(
                $type === InvoiceType::Storno
                    ? __('invoicing.storno_only_for_sent')
                    : __('invoicing.credit_note_only_for_sent_or_paid')
            );
        }

        return DB::transaction(function () use ($original, $type): Invoice {
            $corrective = $this->repository->create([
                'user_id' => $original->user_id,
                'client_id' => $original->client_id,
                'invoice_number' => null,
                'type' => $type->value,
                'related_invoice_id' => $original->id,
                'status' => 'draft',
                'issued_at' => now()->toDateString(),
                'taxable_supply_at' => now()->toDateString(),
                'due_at' => now()->addDays(14)->toDateString(),
                'variable_symbol' => null,
                'bank_account_id' => $original->bank_account_id,
                'currency' => $original->currency->value,
                'subtotal' => 0,
                'discount_percent' => $original->discount_percent,
                'discount_amount' => 0,
                'reverse_charge' => $original->reverse_charge,
                'reverse_charge_mode' => $original->reverse_charge_mode?->value,
                'vat_amount' => 0,
                'total' => 0,
                'note' => $original->note,
            ]);

            foreach ($original->items as $item) {
                $corrective->items()->create([
                    'description' => $item->description,
                    'quantity' => -1 * (float) $item->quantity,
                    'unit' => $item->unit,
                    'unit_price' => $item->unit_price,
                    'vat_rate' => $item->vat_rate,
                    'vat_amount' => 0,
                    'total_excl_vat' => 0,
                    'total_incl_vat' => 0,
                    'sort_order' => $item->sort_order,
                ])->recalculate()->save();
            }

            $corrective->refresh()->recalculateTotals()->save();

            if ($type === InvoiceType::Storno) {
                $this->updateStatusAction->execute($original, InvoiceStatus::Cancelled);
            }

            event(new InvoiceCreated($corrective));

            return $corrective;
        });
    }
}
