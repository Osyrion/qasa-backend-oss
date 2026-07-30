<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Application\Services;

use App\Modules\Invoicing\Domain\Banking\BankAccountIdentity;
use App\Modules\Invoicing\Domain\Banking\CzechIbanConverter;
use App\Modules\Invoicing\Domain\Banking\PaymentQrRequest;
use App\Modules\Invoicing\Domain\Banking\PaymentSchemeRegistry;
use App\Modules\Invoicing\Domain\Models\SupplierInvoice;
use App\Modules\Shared\Exceptions\DomainException;
use chillerlan\QRCode\Common\EccLevel;
use chillerlan\QRCode\Output\QRMarkupSVG;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use Throwable;

/**
 * Payment QR for paying a received invoice from a personal banking app.
 * The scheme (Pay by Square, SPAYD, EPC) is picked by
 * PaymentSchemeRegistry from the vendor's own bank account and the
 * invoice's currency — never tax residency. A domestic-only CZ account is
 * converted to IBAN deterministically since every current scheme needs one.
 */
class SupplierPaymentQrService
{
    public function __construct(
        private readonly PaymentSchemeRegistry $registry,
        private readonly CzechIbanConverter $ibanConverter,
    ) {}

    /**
     * @throws DomainException
     */
    public function dataUri(SupplierInvoice $supplierInvoice): string
    {
        $payload = $this->payload($supplierInvoice);

        try {
            // SVG for the same reason as PaymentQrService: no ext-gd here.
            $options = new QROptions([
                'outputInterface' => QRMarkupSVG::class,
                'outputBase64' => true,
                'eccLevel' => EccLevel::M,
                'svgAddXmlHeader' => true,
            ]);

            return (string) (new QRCode($options))->render($payload);
        } catch (Throwable) {
            throw DomainException::because(__('invoicing.payment_qr_unavailable'));
        }
    }

    /**
     * @throws DomainException
     */
    public function payload(SupplierInvoice $supplierInvoice): string
    {
        $iban = $this->resolveIban($supplierInvoice);

        if ($iban === null) {
            throw DomainException::because(__('invoicing.payment_account_missing'));
        }

        $scheme = $this->registry->schemeFor(BankAccountIdentity::fromIban($iban), $supplierInvoice->currency);

        if ($scheme === null) {
            throw DomainException::because(__('invoicing.payment_qr_currency_not_supported', [
                'currency' => $supplierInvoice->currency->value,
            ]));
        }

        return $scheme->payload(new PaymentQrRequest(
            iban: $iban,
            bic: $supplierInvoice->vendor_bic,
            amount: (float) $supplierInvoice->total,
            currency: $supplierInvoice->currency,
            variableSymbol: $supplierInvoice->variable_symbol,
            beneficiaryName: $this->vendorName($supplierInvoice),
            message: trim($supplierInvoice->supplier_invoice_number.' VS '.($supplierInvoice->variable_symbol ?? '')),
            dueDate: $supplierInvoice->due_at,
        ));
    }

    private function resolveIban(SupplierInvoice $supplierInvoice): ?string
    {
        if ($supplierInvoice->vendor_iban !== null && $supplierInvoice->vendor_iban !== '') {
            return $supplierInvoice->vendor_iban;
        }

        if ($supplierInvoice->hasDomesticVendorAccount()) {
            return $this->ibanConverter->toIban(
                (string) $supplierInvoice->vendor_account_number,
                (string) $supplierInvoice->vendor_bank_code,
            );
        }

        return null;
    }

    private function vendorName(SupplierInvoice $supplierInvoice): string
    {
        $snapshot = $supplierInvoice->vendor_snapshot;

        if ($snapshot !== null && ! empty($snapshot['name'])) {
            return (string) $snapshot['name'];
        }

        return $supplierInvoice->client->display_name ?? '';
    }
}
