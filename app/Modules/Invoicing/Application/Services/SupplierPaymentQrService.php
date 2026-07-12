<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Application\Services;

use App\Modules\Invoicing\Domain\Models\SupplierInvoice;
use App\Modules\Invoicing\Domain\Services\CzechIbanConverter;
use App\Modules\Invoicing\Domain\Services\EpcQrBuilder;
use App\Modules\Invoicing\Domain\Services\SpaydBuilder;
use App\Modules\Shared\Enums\Currency;
use App\Modules\Shared\Exceptions\DomainException;
use chillerlan\QRCode\Common\EccLevel;
use chillerlan\QRCode\Output\QRMarkupSVG;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use Throwable;

/**
 * Payment QR for paying a received invoice from a personal banking app:
 * CZK → SPAYD (QR platba), EUR → SEPA EPC. Both payloads need an IBAN — a
 * domestic-only CZ account is converted deterministically.
 */
class SupplierPaymentQrService
{
    public function __construct(
        private readonly SpaydBuilder $spaydBuilder,
        private readonly EpcQrBuilder $epcBuilder,
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

        $bic = $supplierInvoice->vendor_bic;
        $amount = (float) $supplierInvoice->total;
        $vendorName = $this->vendorName($supplierInvoice);

        return match ($supplierInvoice->currency) {
            Currency::CZK => $this->spaydBuilder->build(
                iban: $iban,
                bic: $bic,
                amount: $amount,
                currency: Currency::CZK,
                variableSymbol: $supplierInvoice->variable_symbol,
                message: $vendorName,
                dueDate: $supplierInvoice->due_at,
            ),
            Currency::EUR => $this->epcBuilder->build(
                iban: $iban,
                bic: $bic,
                beneficiaryName: $vendorName,
                amount: $amount,
                remittanceText: trim($supplierInvoice->supplier_invoice_number.' VS '.($supplierInvoice->variable_symbol ?? '')),
            ),
            default => throw DomainException::because(__('invoicing.payment_qr_currency_not_supported', [
                'currency' => $supplierInvoice->currency->value,
            ])),
        };
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
