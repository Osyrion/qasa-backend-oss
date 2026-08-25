<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Application\Services;

use App\Modules\Invoicing\Domain\Banking\Contracts\PaymentQrScheme;
use App\Modules\Invoicing\Domain\Banking\PaymentQrRequest;
use App\Modules\Invoicing\Domain\Banking\PaymentSchemeRegistry;
use App\Modules\Invoicing\Domain\Models\Invoice;
use App\Modules\Invoicing\Domain\ValueObjects\BankAccountIdentity;
use chillerlan\QRCode\Common\EccLevel;
use chillerlan\QRCode\Output\QRGdImagePNG;
use chillerlan\QRCode\Output\QRImagick;
use chillerlan\QRCode\Output\QRMarkupSVG;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Payment QR printed bottom-right on the invoice PDF. The scheme (Pay by
 * Square, SPAYD, EPC) is picked by PaymentSchemeRegistry from the
 * recipient's own bank account and the document's currency — never by tax
 * residency. Null (no QR) when the account matches no scheme, or the
 * invoice's bank account has no identifiable account at all.
 */
class PaymentQrService
{
    public function __construct(
        private readonly PaymentSchemeRegistry $registry,
    ) {}

    public function dataUri(Invoice $invoice, ?float $amountOverride = null): ?string
    {
        $payload = $this->payload($invoice, $amountOverride);

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
        $scheme = $this->scheme($invoice);

        if ($scheme === null) {
            return null;
        }

        $bank = $this->bankDetails($invoice) ?? [];

        try {
            return $scheme->payload(new PaymentQrRequest(
                iban: (string) ($bank['iban'] ?? ''),
                bic: isset($bank['bic']) && $bank['bic'] !== '' ? (string) $bank['bic'] : null,
                amount: $amountOverride ?? (float) $invoice->total,
                currency: $invoice->currency,
                variableSymbol: $invoice->variable_symbol,
                beneficiaryName: $this->supplierName($invoice),
                message: trim($invoice->invoice_number.' VS '.($invoice->variable_symbol ?? '')),
                dueDate: $invoice->due_at,
            ));
        } catch (Throwable $e) {
            // PayBySquareBuilder shells out to `xz` and throws when it is
            // missing or fails; the QR is decoration on the PDF, the invoice
            // is not. Degrade to no QR and leave a trace, never a 500.
            Log::warning('Payment QR payload could not be built', [
                'invoice_id' => $invoice->getKey(),
                'scheme' => $scheme->name(),
                'exception' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * The scheme this invoice's bank account + currency would resolve to —
     * exposed for InvoiceResource's qr_scheme field, independent of whether
     * a QR is actually rendered.
     */
    public function scheme(Invoice $invoice): ?PaymentQrScheme
    {
        $bank = $this->bankDetails($invoice);

        if ($bank === null) {
            return null;
        }

        // BankAccount::toSnapshot()'s account_number is the combined local
        // format ("123456789/0100"), unlike SupplierInvoice's separate
        // vendor_account_number/vendor_bank_code columns.
        [$domesticAccount, $domesticBankCode] = $this->splitDomesticAccount((string) ($bank['account_number'] ?? ''));

        $identity = BankAccountIdentity::resolve(
            iban: isset($bank['iban']) && $bank['iban'] !== '' ? (string) $bank['iban'] : null,
            accountNumber: $domesticAccount,
            bankCode: $domesticBankCode,
        );

        if ($identity === null || ! $identity->hasIban()) {
            // Every current scheme needs an IBAN payload field — a
            // domestic-only account (no IBAN on file) has no QR today.
            return null;
        }

        return $this->registry->schemeFor($identity, $invoice->currency);
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

    /**
     * @return array{0: string|null, 1: string|null}
     */
    private function splitDomesticAccount(string $accountNumber): array
    {
        if (! str_contains($accountNumber, '/')) {
            return [null, null];
        }

        [$account, $bankCode] = explode('/', $accountNumber, 2);

        return [$account, $bankCode];
    }

    private function supplierName(Invoice $invoice): string
    {
        $snapshot = $invoice->supplier_snapshot;

        if ($snapshot !== null && ! empty($snapshot['name'])) {
            return (string) $snapshot['name'];
        }

        return $invoice->user?->supplierProfile()->name ?? '';
    }
}
