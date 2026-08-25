<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Application\Services;

use App\Modules\Invoicing\Application\Contracts\SupplierInvoicePayments;
use App\Modules\Invoicing\Domain\Models\SupplierInvoice;
use App\Modules\Invoicing\Domain\ValueObjects\PayableSupplierInvoice;
use App\Modules\Invoicing\Domain\ValueObjects\VendorPaymentAccount;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Carbon;

/**
 * The one place outside Invoicing's own code that `supplier_invoices` is read
 * from for payment.
 *
 * The account scope stays on: an invoice the caller's account cannot see is
 * simply not found, which is the same 404 Banking's own `findOrFail()` gave.
 */
final class EloquentSupplierInvoicePayments implements SupplierInvoicePayments
{
    public function payable(bool $excludeHanded = false): array
    {
        $query = SupplierInvoice::query()
            ->payable()
            ->with('client')
            ->orderBy('due_at')
            ->orderBy('internal_number');

        if ($excludeHanded) {
            $query->whereNull('handed_to_payment_at');
        }

        return array_values($query->get()->map($this->toValue(...))->all());
    }

    public function require(string $supplierInvoiceId): PayableSupplierInvoice
    {
        /** @var SupplierInvoice $invoice */
        $invoice = SupplierInvoice::query()->with('client')->findOrFail($supplierInvoiceId);

        return $this->toValue($invoice);
    }

    public function requireAll(array $supplierInvoiceIds): array
    {
        if ($supplierInvoiceIds === []) {
            return [];
        }

        $invoices = SupplierInvoice::query()
            ->with('client')
            ->whereIn('id', $supplierInvoiceIds)
            ->get();

        if ($invoices->count() !== count(array_unique($supplierInvoiceIds))) {
            throw (new ModelNotFoundException)->setModel(SupplierInvoice::class, $supplierInvoiceIds);
        }

        return $invoices
            ->mapWithKeys(fn (SupplierInvoice $invoice): array => [$invoice->id => $this->toValue($invoice)])
            ->all();
    }

    public function markHandedToPayment(array $supplierInvoiceIds): void
    {
        $this->setHandedToPayment($supplierInvoiceIds, now());
    }

    public function clearHandedToPayment(array $supplierInvoiceIds): void
    {
        $this->setHandedToPayment($supplierInvoiceIds, null);
    }

    public function recordAccountVerification(string $supplierInvoiceId, string $result, Carbon $at): void
    {
        SupplierInvoice::query()
            ->whereKey($supplierInvoiceId)
            ->update(['account_verified_at' => $at, 'account_verification_result' => $result]);
    }

    /**
     * @param  list<string>  $supplierInvoiceIds
     */
    private function setHandedToPayment(array $supplierInvoiceIds, ?Carbon $at): void
    {
        if ($supplierInvoiceIds === []) {
            return;
        }

        SupplierInvoice::query()
            ->whereIn('id', $supplierInvoiceIds)
            ->update(['handed_to_payment_at' => $at]);
    }

    private function toValue(SupplierInvoice $invoice): PayableSupplierInvoice
    {
        return new PayableSupplierInvoice(
            id: $invoice->id,
            internalNumber: $invoice->internal_number,
            supplierInvoiceNumber: $invoice->supplier_invoice_number,
            vendorName: $this->frozen($invoice, 'name') ?? ($invoice->client->display_name ?? ''),
            vendorTaxId: $this->frozen($invoice, 'dic') ?? $invoice->client?->dic,
            vendorCountry: $this->frozen($invoice, 'country') ?? $invoice->client?->country,
            dueAt: $invoice->due_at,
            total: (float) $invoice->total,
            currency: $invoice->currency,
            variableSymbol: $invoice->variable_symbol,
            account: new VendorPaymentAccount(
                accountNumber: $invoice->vendor_account_number,
                bankCode: $invoice->vendor_bank_code,
                iban: $invoice->vendor_iban,
                bic: $invoice->vendor_bic,
                source: $invoice->account_source,
                verifiedAt: $invoice->account_verified_at,
                verificationResult: $invoice->account_verification_result,
            ),
            handedToPayment: $invoice->handed_to_payment_at,
            status: $invoice->status,
        );
    }

    /**
     * What the document froze about the vendor at receipt, when it froze
     * anything. The live client is the fallback, never the other way round —
     * a document names who it named.
     */
    private function frozen(SupplierInvoice $invoice, string $key): ?string
    {
        $value = $invoice->vendor_snapshot[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }
}
