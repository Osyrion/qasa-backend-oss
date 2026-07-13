<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Application\Services;

use App\Modules\Invoicing\Domain\Models\BankAccount;
use App\Modules\Invoicing\Domain\Models\SupplierInvoice;
use App\Modules\Shared\Enums\Currency;

/**
 * Unpaid supplier invoices grouped for the payment-order builder screen:
 * `abo_eligible` (CZK with a domestic account — exportable as ABO/KPC),
 * `sepa_eligible` (EUR with an IBAN on file — exportable as SEPA pain.001)
 * and `other` (everything else — CSV/PDF only). Groups are mutually
 * exclusive. sepa_eligible only checks for a direct IBAN — it deliberately
 * does not attempt the CzechIbanConverter fallback the export builder uses,
 * to keep this a simple heuristic for the UI rather than duplicating the
 * builder's conversion logic.
 */
class PaymentOrderCandidatesService
{
    /**
     * @return array{abo_eligible: list<array<string, mixed>>, sepa_eligible: list<array<string, mixed>>, other: list<array<string, mixed>>}
     */
    public function candidates(?BankAccount $payerAccount, bool $hideHanded = false): array
    {
        $query = SupplierInvoice::query()
            ->payable()
            ->with('client')
            ->orderBy('due_at')
            ->orderBy('internal_number');

        if ($hideHanded) {
            $query->whereNull('handed_to_payment_at');
        }

        $aboEligible = [];
        $sepaEligible = [];
        $other = [];

        foreach ($query->get() as $invoice) {
            $row = $this->row($invoice, $payerAccount);

            if ($invoice->currency === Currency::CZK && $invoice->hasDomesticVendorAccount()) {
                $aboEligible[] = $row;
            } elseif ($invoice->currency === Currency::EUR && $invoice->vendor_iban !== null && $invoice->vendor_iban !== '') {
                $sepaEligible[] = $row;
            } else {
                $other[] = $row;
            }
        }

        return ['abo_eligible' => $aboEligible, 'sepa_eligible' => $sepaEligible, 'other' => $other];
    }

    /**
     * @return array<string, mixed>
     */
    private function row(SupplierInvoice $invoice, ?BankAccount $payerAccount): array
    {
        [$selectable, $reason] = $this->selectable($invoice, $payerAccount);

        return [
            'id' => $invoice->id,
            'vendor_name' => $this->vendorName($invoice),
            'internal_number' => $invoice->internal_number,
            'supplier_invoice_number' => $invoice->supplier_invoice_number,
            'due_at' => $invoice->due_at?->toDateString(),
            'is_overdue' => $invoice->due_at !== null && $invoice->due_at->isPast() && ! $invoice->due_at->isToday(),
            'amount' => (float) $invoice->total,
            'currency' => $invoice->currency->value,
            'variable_symbol' => $invoice->variable_symbol,
            'account' => [
                'account_number' => $invoice->vendor_account_number,
                'bank_code' => $invoice->vendor_bank_code,
                'iban' => $invoice->vendor_iban,
                'bic' => $invoice->vendor_bic,
                'source' => $invoice->account_source,
                'verified_at' => $invoice->account_verified_at?->toISOString(),
                'verification_result' => $invoice->account_verification_result,
            ],
            'handed_to_payment_at' => $invoice->handed_to_payment_at?->toISOString(),
            'selectable' => $selectable,
            'selectable_reason' => $reason,
        ];
    }

    /**
     * @return array{0: bool, 1: string|null}
     */
    private function selectable(SupplierInvoice $invoice, ?BankAccount $payerAccount): array
    {
        if (! $invoice->hasPaymentAccount()) {
            return [false, (string) __('invoicing.payment_order_reason_account_missing')];
        }

        if ($payerAccount !== null && $invoice->currency !== $payerAccount->currency) {
            return [false, (string) __('invoicing.payment_order_reason_currency_mismatch', [
                'currency' => $invoice->currency->value,
                'payer_currency' => $payerAccount->currency->value,
            ])];
        }

        return [true, null];
    }

    private function vendorName(SupplierInvoice $invoice): string
    {
        $snapshot = $invoice->vendor_snapshot;

        if ($snapshot !== null && ! empty($snapshot['name'])) {
            return (string) $snapshot['name'];
        }

        return $invoice->client->display_name ?? '';
    }
}
