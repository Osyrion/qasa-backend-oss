<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Application\Services;

use App\Modules\Invoicing\Application\DTOs\QuotePdfViewModel;
use App\Modules\Invoicing\Domain\Models\Quote;
use App\Modules\Invoicing\Domain\Services\CountryTaxLabelMap;
use App\Modules\Invoicing\Domain\Services\VatRecapCalculator;
use App\Modules\Shared\Enums\VatStatus;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Thin variant of InvoicePdfService for quotes: no QR payment, no bank
 * details, always printed as "not a tax document".
 */
class QuotePdfService
{
    public function __construct(
        private readonly VatRecapCalculator $recapCalculator,
    ) {}

    public function generate(Quote $quote): string
    {
        $quote->loadMissing(['client', 'items', 'user']);

        $locale = $quote->client->locale ?? $quote->user->locale ?? 'sk';
        App::setLocale($locale);

        $pdf = Pdf::loadView('invoices::quote-pdf', [
            'vm' => $this->viewModel($quote),
        ]);

        // Same hardening as InvoicePdfService: never fetch remote resources
        // and never evaluate inline PHP from the template.
        $pdf->setOptions([
            'isRemoteEnabled' => false,
            'isPhpEnabled' => false,
        ]);

        $pdf->setPaper('A4', 'portrait');

        return $pdf->output();
    }

    public function viewModel(Quote $quote): QuotePdfViewModel
    {
        $supplier = $this->supplierData($quote);
        $client = $this->clientData($quote);

        return new QuotePdfViewModel(
            quote: $quote,
            documentTitle: (string) __('invoices::pdf.title_quote').' '.$quote->quote_number,
            supplier: $supplier,
            supplierTaxLines: $this->taxLines($supplier),
            logoDataUri: $this->logoDataUri($supplier['logo_path'] ?? null),
            client: $client,
            clientTaxLines: $this->taxLines($client),
            vatRecap: $this->recapCalculator->recapForQuote($quote),
            footerText: $supplier['invoice_footer_text'] ?? null,
        );
    }

    public function filename(Quote $quote): string
    {
        return sprintf(
            '%s_%s.pdf',
            str_replace(['/', '\\', ' '], '-', $quote->quote_number),
            $quote->issued_at->format('Y-m-d'),
        );
    }

    /**
     * @return array<string, mixed> issued snapshot first, live user for drafts
     */
    private function supplierData(Quote $quote): array
    {
        if ($quote->supplier_snapshot !== null) {
            return $quote->supplier_snapshot;
        }

        $user = $quote->user;

        assert($user !== null);

        return [
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
    }

    /**
     * @return array<string, mixed>
     */
    private function clientData(Quote $quote): array
    {
        if ($quote->client_snapshot !== null) {
            return $quote->client_snapshot;
        }

        $client = $quote->client;

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
     * @param  array<string, mixed>  $party
     * @return array<string, string> label => value
     */
    private function taxLines(array $party): array
    {
        if ($party === []) {
            return [];
        }

        $labels = CountryTaxLabelMap::labelsFor(
            (string) ($party['country'] ?? ''),
            $this->partyVatStatus($party),
        );

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
