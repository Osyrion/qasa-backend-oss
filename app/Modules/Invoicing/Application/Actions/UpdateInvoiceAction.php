<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Application\Actions;

use App\Modules\Clients\Application\Contracts\ClientUsageGuardInterface;
use App\Modules\Clients\Domain\Models\Client;
use App\Modules\Invoicing\Application\Contracts\InvoiceRepositoryInterface;
use App\Modules\Invoicing\Application\DTOs\InvoiceData;
use App\Modules\Invoicing\Domain\Models\Invoice;
use App\Modules\Shared\Exceptions\DomainException;
use App\Modules\Taxation\Application\Contracts\TaxSystemResolverInterface;
use Illuminate\Support\Facades\DB;
use Throwable;

readonly class UpdateInvoiceAction
{
    public function __construct(
        private InvoiceRepositoryInterface $repository,
        private TaxSystemResolverInterface $taxSystemResolver,
        private ClientUsageGuardInterface $usageGuard,
    ) {}

    /**
     * Update a draft invoice's header fields. The invoice number and type
     * are fixed at creation; items have their own endpoints.
     *
     * @throws DomainException
     * @throws Throwable
     */
    public function execute(Invoice $invoice, InvoiceData $data): Invoice
    {
        if (! $invoice->isEditable()) {
            throw DomainException::because(__('invoicing.only_draft_editable'));
        }

        if ($invoice->client !== null) {
            $this->usageGuard->ensureUsable($invoice->client);
        }

        if ($data->client_id !== $invoice->client_id) {
            $client = Client::query()->find($data->client_id);

            if ($client !== null) {
                $this->usageGuard->ensureUsable($client);
            }
        }

        return DB::transaction(function () use ($invoice, $data): Invoice {
            $user = $invoice->user;
            assert($user !== null);
            $owner = $user->accountOwner();
            $client = Client::forUser($owner->id)->findOrFail($data->client_id);

            $decision = $this->taxSystemResolver->forUser($owner)->vatRegimeResolver()->resolve(
                $owner->vat_status,
                $client,
                $data->reverse_charge,
            );

            $updated = $this->repository->update($invoice, [
                'client_id' => $data->client_id,
                'issued_at' => $data->issued_at,
                'taxable_supply_at' => $data->taxable_supply_at,
                'due_at' => $data->due_at,
                'variable_symbol' => $data->variable_symbol,
                'bank_account_id' => $data->bank_account_id,
                'currency' => $data->currency->value,
                'discount_percent' => $data->discount_percent,
                'reverse_charge' => $decision->reverseCharge,
                'reverse_charge_mode' => $decision->mode?->value,
                'note' => $data->note,
                'note_above' => $data->note_above,
            ]);

            $updated->refresh();

            if ($decision->reverseCharge) {
                $updated->normalizeItemsForReverseCharge();
            }

            $updated->recalculateTotals()->save();

            return $updated;
        });
    }
}
