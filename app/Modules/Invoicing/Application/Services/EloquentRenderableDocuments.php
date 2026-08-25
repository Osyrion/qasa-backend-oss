<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Application\Services;

use App\Modules\Invoicing\Application\Contracts\InvoiceRepositoryInterface;
use App\Modules\Invoicing\Application\Contracts\RenderableDocuments;
use App\Modules\Invoicing\Application\Contracts\SupplierInvoiceRepositoryInterface;
use App\Modules\Invoicing\Application\DTOs\InvoiceExportData;
use App\Modules\Invoicing\Application\DTOs\SupplierInvoiceExportData;
use App\Modules\Invoicing\Domain\Models\Invoice;
use App\Modules\Invoicing\Domain\Models\InvoiceItem;
use App\Modules\Invoicing\Domain\Models\SupplierInvoice;
use App\Modules\Invoicing\Domain\Models\SupplierInvoiceVatLine;
use App\Modules\Invoicing\Domain\Services\VatRecapCalculator;
use App\Modules\Invoicing\Domain\ValueObjects\DocumentBankAccount;
use App\Modules\Invoicing\Domain\ValueObjects\DocumentParty;
use App\Modules\Invoicing\Domain\ValueObjects\DocumentSupplier;
use App\Modules\Invoicing\Domain\ValueObjects\RenderableInvoice;
use App\Modules\Invoicing\Domain\ValueObjects\RenderableInvoiceItem;
use App\Modules\Invoicing\Domain\ValueObjects\RenderableSupplierInvoice;
use App\Modules\Invoicing\Domain\ValueObjects\VatRecapRow;

/**
 * The one place a document turns into something another module may print.
 *
 * Every figure is read from what the document already stores, never
 * recomputed — the one exception being the VAT recap, which is derived from
 * the items and the header discount and has nowhere else to come from. That is
 * also why it belongs here: it was being derived three times, in three
 * exporters, each holding Invoicing's Domain calculator to do it.
 */
final readonly class EloquentRenderableDocuments implements RenderableDocuments
{
    public function __construct(
        private InvoiceRepositoryInterface $invoices,
        private SupplierInvoiceRepositoryInterface $supplierInvoices,
        private VatRecapCalculator $recapCalculator,
    ) {}

    public function invoice(string $invoiceId): RenderableInvoice
    {
        return $this->renderable($this->invoices->findByIdOrFail($invoiceId));
    }

    public function invoicesForExport(InvoiceExportData $filter): array
    {
        return array_values($this->invoices->forExport($filter)
            ->map(fn (Invoice $invoice): RenderableInvoice => $this->renderable($invoice))
            ->all());
    }

    public function supplierInvoicesForExport(SupplierInvoiceExportData $filter): array
    {
        return array_values($this->supplierInvoices->forExport($filter)
            ->map(fn (SupplierInvoice $invoice): RenderableSupplierInvoice => new RenderableSupplierInvoice(
                id: $invoice->id,
                number: $invoice->supplier_invoice_number,
                currency: $invoice->currency,
                issuedAt: $invoice->issued_at,
                dueAt: $invoice->due_at,
                taxableSupplyAt: $invoice->taxable_supply_at,
                variableSymbol: $invoice->variable_symbol,
                total: (float) $invoice->total,
                vendor: DocumentParty::fromSnapshot($invoice->vendor_snapshot),
                vatLines: array_values($invoice->vatLines->map(
                    static fn (SupplierInvoiceVatLine $line): VatRecapRow => new VatRecapRow(
                        rate: (float) $line->vat_rate,
                        base: (float) $line->base,
                        vat: (float) $line->vat_amount,
                        total: (float) $line->base + (float) $line->vat_amount,
                    ),
                )->all()),
            ))
            ->all());
    }

    private function renderable(Invoice $invoice): RenderableInvoice
    {
        $invoice->loadMissing('items');

        return new RenderableInvoice(
            id: $invoice->id,
            number: $invoice->invoice_number,
            type: $invoice->type,
            status: $invoice->statusEnum(),
            currency: $invoice->currency,
            issuedAt: $invoice->issued_at,
            dueAt: $invoice->due_at,
            taxableSupplyAt: $invoice->taxable_supply_at,
            variableSymbol: $invoice->variable_symbol,
            subtotal: (float) $invoice->subtotal,
            vatAmount: (float) $invoice->vat_amount,
            total: (float) $invoice->total,
            balance: $invoice->balance(),
            reverseCharge: (bool) $invoice->reverse_charge,
            exchangeRate: $invoice->exchange_rate_snapshot !== null ? (float) $invoice->exchange_rate_snapshot : null,
            note: $invoice->note,
            noteAbove: $invoice->note_above,
            supplier: DocumentSupplier::fromSnapshot($invoice->supplier_snapshot),
            client: DocumentParty::fromSnapshot($invoice->client_snapshot),
            bankAccount: DocumentBankAccount::fromSnapshot($invoice->bank_account_snapshot),
            items: array_values($invoice->items->map(
                static fn (InvoiceItem $item): RenderableInvoiceItem => new RenderableInvoiceItem(
                    description: $item->description,
                    quantity: (float) $item->quantity,
                    unit: $item->unit,
                    unitPrice: (float) $item->unit_price,
                    vatRate: (float) $item->vat_rate,
                    vatAmount: (float) $item->vat_amount,
                    totalExclVat: (float) $item->total_excl_vat,
                    totalInclVat: (float) $item->total_incl_vat,
                ),
            )->all()),
            vatRecap: $this->recapCalculator->recap($invoice),
            nationalCurrencyRecap: $this->recapCalculator->czkRecap($invoice),
        );
    }
}
