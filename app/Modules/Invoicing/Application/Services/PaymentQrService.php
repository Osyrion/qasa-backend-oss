<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Application\Services;

use App\Modules\Invoicing\Domain\Models\Invoice;
use App\Modules\Invoicing\Domain\Services\EpcQrBuilder;
use App\Modules\Invoicing\Domain\Services\SpaydBuilder;
use App\Modules\Shared\Enums\Currency;
use chillerlan\QRCode\Common\EccLevel;
use chillerlan\QRCode\Output\QRGdImagePNG;
use chillerlan\QRCode\Output\QRImagick;
use chillerlan\QRCode\Output\QRMarkupSVG;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use Throwable;

/**
 * Payment QR printed bottom-right on the invoice PDF:
 * CZK → SPAYD (QR platba), EUR → SEPA EPC. Null (no QR) for other
 * currencies or when the invoice's bank account has no IBAN.
 */
class PaymentQrService
{
    public function __construct(
        private readonly SpaydBuilder $spaydBuilder,
        private readonly EpcQrBuilder $epcBuilder,
    ) {}

    public function dataUri(Invoice $invoice): ?string
    {
        $payload = $this->payload($invoice);

        if ($payload === null) {
            return null;
        }

        try {
            // SVG (not GD/PNG): the runtime has no ext-gd and dompdf
            // renders SVG data URIs via its bundled php-svg-lib
            $options = new QROptions([
                'outputInterface' => QRMarkupSVG::class,
                'outputBase64' => true,
                'eccLevel' => EccLevel::M,
                'svgAddXmlHeader' => true,
            ]);

            return (string) (new QRCode($options))->render($payload);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Binary PNG payment QR for embedding in HTML emails — mail clients do
     * not render the SVG data URIs used on the PDF. Prefers imagick (the
     * production image), falls back to gd; returns null when neither
     * extension is loaded or the invoice has no QR payload, so callers
     * must degrade gracefully.
     */
    public function png(Invoice $invoice, ?float $amountOverride = null): ?string
    {
        $payload = $this->payload($invoice, $amountOverride);

        if ($payload === null) {
            return null;
        }

        $outputInterface = match (true) {
            extension_loaded('imagick') => QRImagick::class,
            extension_loaded('gd') => QRGdImagePNG::class,
            default => null,
        };

        if ($outputInterface === null) {
            return null;
        }

        try {
            $options = new QROptions([
                'outputInterface' => $outputInterface,
                'outputBase64' => false,
                'eccLevel' => EccLevel::M,
                'scale' => 6,
                'imagickFormat' => 'png',
            ]);

            return (string) (new QRCode($options))->render($payload);
        } catch (Throwable) {
            return null;
        }
    }

    public function payload(Invoice $invoice, ?float $amountOverride = null): ?string
    {
        $bank = $this->bankDetails($invoice);

        if ($bank === null || empty($bank['iban'])) {
            return null;
        }

        $iban = (string) $bank['iban'];
        $bic = isset($bank['bic']) && $bank['bic'] !== '' ? (string) $bank['bic'] : null;
        $amount = $amountOverride ?? (float) $invoice->total;

        return match ($invoice->currency) {
            Currency::CZK => $this->spaydBuilder->build(
                iban: $iban,
                bic: $bic,
                amount: $amount,
                currency: Currency::CZK,
                variableSymbol: $invoice->variable_symbol,
                message: $invoice->invoice_number,
                dueDate: $invoice->due_at,
            ),
            Currency::EUR => $this->epcBuilder->build(
                iban: $iban,
                bic: $bic,
                beneficiaryName: $this->supplierName($invoice),
                amount: $amount,
                remittanceText: trim($invoice->invoice_number.' VS '.($invoice->variable_symbol ?? '')),
            ),
            default => null,
        };
    }

    /**
     * @return array<string, mixed>|null issued snapshot first, live relation for draft previews
     */
    public function bankDetails(Invoice $invoice): ?array
    {
        if ($invoice->bank_account_snapshot !== null) {
            return $invoice->bank_account_snapshot;
        }

        return $invoice->bankAccount?->toSnapshot();
    }

    private function supplierName(Invoice $invoice): string
    {
        $snapshot = $invoice->supplier_snapshot;

        if ($snapshot !== null && ! empty($snapshot['name'])) {
            return (string) $snapshot['name'];
        }

        return $invoice->user->full_name ?? '';
    }
}
