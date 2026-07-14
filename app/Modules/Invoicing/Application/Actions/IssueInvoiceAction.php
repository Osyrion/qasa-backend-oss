<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Application\Actions;

use App\Modules\Invoicing\Application\Contracts\ExchangeRateServiceInterface;
use App\Modules\Invoicing\Application\Contracts\InvoiceRepositoryInterface;
use App\Modules\Invoicing\Application\Services\ViesPreconditionService;
use App\Modules\Invoicing\Domain\Enums\ReverseChargeMode;
use App\Modules\Invoicing\Domain\Models\Invoice;
use App\Modules\Shared\Enums\Currency;
use App\Modules\Shared\Exceptions\DomainException;

/**
 * The draft → sent/issued moment: assigns the invoice_number (chronological,
 * per-account sequence — never assigned earlier so a deleted draft never
 * leaves a gap), freezes supplier/client/bank snapshots so later profile
 * edits don't rewrite issued documents, snapshots the ČNB rate to CZK for
 * foreign-currency invoices, and fills VS/DUZP defaults.
 *
 * Failures of the ČNB fetch must not block issuance — the rate snapshot
 * simply stays null and the PDF omits the CZK conversion table.
 */
readonly class IssueInvoiceAction
{
    public function __construct(
        private InvoiceRepositoryInterface $repository,
        private ExchangeRateServiceInterface $exchangeRateService,
        private ViesPreconditionService $viesPrecondition,
    ) {}

    /**
     * Fetch the ČNB rate for a foreign-currency invoice. This may perform an
     * HTTP request, so call it before opening the transaction that will run
     * execute(). Returns null when no rate is needed or the fetch failed.
     */
    public function resolveExchangeRateSnapshot(Invoice $invoice): ?float
    {
        if ($invoice->currency === Currency::CZK || $invoice->exchange_rate_snapshot !== null) {
            return null;
        }

        return $this->exchangeRateService->getRateOrFetchCnb(
            base: $invoice->currency,
            userId: $invoice->user_id,
            date: $invoice->issued_at->toDateString(),
        );
    }

    /**
     * @throws DomainException
     */
    public function execute(Invoice $invoice, ?float $exchangeRate = null): Invoice
    {
        $invoice->loadMissing(['user', 'client', 'bankAccount', 'items']);

        $user = $invoice->user;
        $client = $invoice->client;

        assert($user !== null);

        // Never reassign — grandfathered drafts that already carry a number
        // (created before this action owned assignment, or issued once
        // already) keep it. This is the single point where a number is born.
        if ($invoice->invoice_number === null) {
            $invoice->invoice_number = $this->repository->nextInvoiceNumber(
                userId: $invoice->user_id,
                mask: $invoice->type->numberMask($user),
                start: $user->accountOwner()->invoice_number_start ?? 1,
            );
        }

        if ($invoice->reverse_charge_mode === ReverseChargeMode::Eu) {
            assert($client !== null);
            $this->viesPrecondition->ensureVerified($client);
        }

        if (! $user->vat_status->canChargeVat() && $invoice->items->contains(fn ($item): bool => (float) $item->vat_rate > 0.0)) {
            throw DomainException::because(__('invoicing.non_payer_cannot_charge_vat'));
        }

        $invoice->supplier_snapshot = [
            'name' => $user->full_name,
            'ico' => $user->ico,
            'dic' => $user->dic,
            'vat_id' => $user->vat_id,
            'is_vat_payer' => $user->is_vat_payer,
            'vat_status' => $user->vat_status->value,
            'address' => $user->address,
            'city' => $user->city,
            'postal_code' => $user->postal_code,
            'country' => $user->country,
            'email' => $user->email,
            'phone' => $user->phone,
            'website' => $user->website,
            'logo_path' => $user->logo_path,
            'invoice_footer_text' => $user->invoice_footer_text,
        ];

        $invoice->client_snapshot = $client === null ? null : [
            'name' => $client->display_name,
            'ico' => $client->ico,
            'dic' => $client->dic,
            'vat_id' => $client->vat_id,
            'is_vat_payer' => $client->is_vat_payer,
            'address' => $client->address,
            'city' => $client->city,
            'postal_code' => $client->postal_code,
            'country' => $client->country,
            'email' => $client->email,
            'phone' => $client->phone,
        ];

        $invoice->bank_account_snapshot = $invoice->bankAccount?->toSnapshot();

        if ($exchangeRate !== null && $invoice->exchange_rate_snapshot === null) {
            $invoice->exchange_rate_snapshot = $exchangeRate;
        }

        if ($invoice->variable_symbol === null) {
            $invoice->variable_symbol = CreateInvoiceAction::variableSymbolFromNumber($invoice->invoice_number);
        }

        if ($invoice->taxable_supply_at === null && $invoice->type->isTaxDocument()) {
            $invoice->taxable_supply_at = $invoice->issued_at;
        }

        $invoice->recalculateTotals();
        $invoice->save();

        return $invoice;
    }
}
