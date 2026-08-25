<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Application\Services;

use App\Modules\Invoicing\Application\DTOs\InvoicePdfViewModel;
use App\Modules\Invoicing\Domain\Enums\InvoiceType;
use App\Modules\Invoicing\Domain\Models\Invoice;
use App\Modules\Invoicing\Domain\Services\VatRecapCalculator;
use App\Modules\Shared\Enums\VatStatus;
use App\Modules\Shared\Support\ClientTaxLabelMap;
use App\Modules\Taxation\Application\Contracts\TaxSystemResolverInterface;
use App\Modules\Taxation\Domain\Enums\TaxResidency;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Storage;
use Throwable;

class InvoicePdfService
{
    public function __construct(
        private readonly VatRecapCalculator $recapCalculator,
        private readonly PaymentQrService $paymentQrService,
        private readonly TaxSystemResolverInterface $taxSystemResolver,
    ) {}

    public function generate(Invoice $invoice): string
    {
        $invoice->loadMissing(['client', 'items', 'user', 'bankAccount', 'relatedInvoice', 'workReportLines']);

        // Set locale for invoice language
        $locale = $invoice->client->locale ?? $invoice->user?->preferredLocale() ?? 'sk';
        App::setLocale($locale);

        $pdf = Pdf::loadView('invoices::pdf', [
            'vm' => $this->viewModel($invoice),
        ]);

        // Harden the renderer: never fetch remote resources (SSRF via
        // <img src="http…"> / user-supplied URLs) and never evaluate inline
        // PHP from the template. Defaults already disable these, but pinning
        // them keeps a future config change from silently re-enabling them.
        $pdf->setOptions([
            'isRemoteEnabled' => false,
            'isPhpEnabled' => false,
        ]);

        $pdf->setPaper('A4', 'portrait');

        return $pdf->output();
    }

    public function viewModel(Invoice $invoice): InvoicePdfViewModel
    {
        $supplier = $this->supplierData($invoice);
        $client = $this->clientData($invoice);
        $bank = $invoice->bank_account_snapshot ?? $invoice->bankAccount?->toSnapshot();

        $type = $invoice->type ?? InvoiceType::Invoice;
        $supplierStatus = $this->partyVatStatus($supplier);
        $reverseCharge = (bool) $invoice->reverse_charge;

        // A reverse-charged invoice is always a full tax document (with
        // DUZP); otherwise only a full VAT payer's invoice is — an
        // identified person's domestic supply and a non-payer's invoice
        // print the same plain "not a tax document" heading as each other.
        $isTaxDocumentHeading = $reverseCharge || $supplierStatus->canChargeVat();
        $showVatColumns = $supplierStatus->canChargeVat() && ! $reverseCharge;

        $czkRecap = $type->isTaxDocument()
            ? $this->recapCalculator->czkRecap($invoice)
            : null;

        $titleText = $type === InvoiceType::Invoice
            ? (string) __('invoices::pdf.'.($isTaxDocumentHeading ? 'title_tax_document' : 'title_invoice'))
            : $type->label();

        return new InvoicePdfViewModel(
            invoice: $invoice,
            documentTitle: $titleText.' '.($invoice->invoice_number ?? (string) __('invoices::pdf.draft_number_placeholder')),
            isTaxDocument: $type->isTaxDocument(),
            relatedInvoiceNumber: $invoice->relatedInvoice?->invoice_number,
            supplier: $supplier,
            supplierTaxLines: $this->supplierTaxLines($supplier),
            supplierVatStatus: $supplierStatus,
            showVatColumns: $showVatColumns,
            showVatRecap: $showVatColumns,
            showTaxableSupplyDate: $type->isTaxDocument() && $isTaxDocumentHeading,
            vatNote: $isTaxDocumentHeading ? null : (string) __('invoices::pdf.not_vat_payer'),
            reverseChargeClause: $invoice->reverse_charge_mode !== null
                ? (string) __('invoices::pdf.'.$invoice->reverse_charge_mode->clauseKey((string) ($supplier['country'] ?? '')))
                : null,
            totalLabel: (string) __('invoices::pdf.'.($reverseCharge ? 'total_excl_vat' : 'total_due')),
            logoDataUri: $this->logoDataUri($supplier['logo_path'] ?? null),
            client: $client,
            clientTaxLines: $this->clientTaxLines($client),
            bank: $bank,
            vatRecap: $this->recapCalculator->recap($invoice),
            czkRecap: $czkRecap,
            exchangeRate: $invoice->exchange_rate_snapshot !== null
                ? (float) $invoice->exchange_rate_snapshot
                : null,
            qrDataUri: $this->paymentQrService->dataUri($invoice),
            footerText: $supplier['invoice_footer_text'] ?? null,
            workReportLines: $invoice->workReportLines,
        );
    }

    public function filename(Invoice $invoice): string
    {
        return sprintf(
            '%s_%s.pdf',
            str_replace(['/', '\\', ' '], '-', $invoice->invoice_number ?? 'draft'),
            $invoice->issued_at->format('Y-m-d'),
        );
    }

    /**
     * @return array<string, mixed> issued snapshot first, live user for drafts
     */
    private function supplierData(Invoice $invoice): array
    {
        if ($invoice->supplier_snapshot !== null) {
            return $invoice->supplier_snapshot;
        }

        $user = $invoice->user;

        assert($user !== null);

        return $user->supplierProfile()->toSnapshot();
    }

    /**
     * @return array<string, mixed>
     */
    private function clientData(Invoice $invoice): array
    {
        if ($invoice->client_snapshot !== null) {
            return $invoice->client_snapshot;
        }

        $client = $invoice->client;

        if ($client === null) {
            return [];
        }

        return [
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
    }

    /**
     * Supplier (tenant) tax identifier lines, via TaxSystem::taxLabels() —
     * the tenant's own residency, e.g. SK VAT payer → IČO, DIČ, IČ DPH.
     * Falls back to ClientTaxLabelMap for the rare case of malformed/legacy
     * data where the country isn't a valid residency.
     *
     * @param  array<string, mixed>  $supplier
     * @return array<string, string> label => value
     */
    private function supplierTaxLines(array $supplier): array
    {
        if ($supplier === []) {
            return [];
        }

        $status = $this->partyVatStatus($supplier);
        $residency = TaxResidency::tryFrom(strtoupper((string) ($supplier['country'] ?? '')));

        $labels = $residency !== null
            ? $this->taxSystemResolver->forResidency($residency)->taxLabels($status)
            : ClientTaxLabelMap::labelsFor((string) ($supplier['country'] ?? ''), $status);

        return $this->linesFromLabels($supplier, $labels);
    }

    /**
     * Client tax identifier lines — a client can be from any country, not
     * just SK/CZ, so this always goes through Invoicing's ClientTaxLabelMap.
     *
     * @param  array<string, mixed>  $client
     * @return array<string, string> label => value
     */
    private function clientTaxLines(array $client): array
    {
        if ($client === []) {
            return [];
        }

        $labels = ClientTaxLabelMap::labelsFor((string) ($client['country'] ?? ''), $this->partyVatStatus($client));

        return $this->linesFromLabels($client, $labels);
    }

    /**
     * @param  array<string, mixed>  $party
     * @param  array<string, string>  $labels
     * @return array<string, string> label => value
     */
    private function linesFromLabels(array $party, array $labels): array
    {
        $lines = [];

        foreach ($labels as $field => $label) {
            $value = $party[$field] ?? null;

            if ($value !== null && $value !== '') {
                $lines[$label] = (string) $value;
            }
        }

        return $lines;
    }

    /**
     * Resolves a supplier/client's VAT status from its snapshot or live
     * record. Snapshots frozen before vat_status existed only carry the
     * legacy is_vat_payer boolean, so they're derived from it — this keeps
     * old issued invoices rendering exactly as they did at issue time.
     *
     * @param  array<string, mixed>  $party
     */
    private function partyVatStatus(array $party): VatStatus
    {
        if (isset($party['vat_status'])) {
            return VatStatus::from((string) $party['vat_status']);
        }

        return VatStatus::fromLegacyBool((bool) ($party['is_vat_payer'] ?? false));
    }

    private function logoDataUri(?string $logoPath): ?string
    {
        if ($logoPath === null || $logoPath === '') {
            return null;
        }

        try {
            $disk = Storage::disk('public');

            if (! $disk->exists($logoPath)) {
                return null;
            }

            $mime = $disk->mimeType($logoPath) ?: 'image/png';

            return 'data:'.$mime.';base64,'.base64_encode((string) $disk->get($logoPath));
        } catch (Throwable) {
            return null;
        }
    }
}
