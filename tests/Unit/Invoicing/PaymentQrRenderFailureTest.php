<?php

declare(strict_types=1);

use App\Modules\Invoicing\Application\Services\PaymentQrService;
use App\Modules\Invoicing\Application\Services\SupplierPaymentQrService;
use App\Modules\Invoicing\Domain\Banking\Contracts\PaymentQrScheme;
use App\Modules\Invoicing\Domain\Banking\PaymentQrRequest;
use App\Modules\Invoicing\Domain\Banking\PaymentSchemeRegistry;
use App\Modules\Invoicing\Domain\Models\Invoice;
use App\Modules\Invoicing\Domain\Models\SupplierInvoice;
use App\Modules\Invoicing\Domain\ValueObjects\BankAccountIdentity;
use App\Modules\Shared\Enums\Currency;
use App\Modules\Shared\Exceptions\DomainException;
use App\Modules\Shared\Support\CzechIbanConverter;

/**
 * PayBySquareBuilder shells out to `xz` and throws RuntimeException when that
 * binary is missing or fails. The whole point of the QR being optional on the
 * PDF is that such a failure costs the QR, never the invoice.
 */
function brokenSchemeRegistry(): PaymentSchemeRegistry
{
    return new PaymentSchemeRegistry([
        new class implements PaymentQrScheme
        {
            public function name(): string
            {
                return 'exploding';
            }

            public function supports(BankAccountIdentity $account, Currency $currency): bool
            {
                return true;
            }

            public function payload(PaymentQrRequest $request): string
            {
                throw new RuntimeException('xz exited with code 127.');
            }
        },
    ]);
}

function invoiceWithSkIban(): Invoice
{
    $invoice = new Invoice;
    $invoice->invoice_number = '2026001';
    $invoice->variable_symbol = '2026001';
    $invoice->currency = Currency::EUR;
    $invoice->total = '120.00';
    $invoice->bank_account_snapshot = ['iban' => 'SK3112000000198742637541'];
    $invoice->supplier_snapshot = ['name' => 'Test s.r.o.'];

    return $invoice;
}

it('degrades to no QR when the payload builder throws', function (): void {
    $service = new PaymentQrService(brokenSchemeRegistry());

    expect($service->dataUri(invoiceWithSkIban()))->toBeNull();
});

it('degrades to no QR payload when the payload builder throws', function (): void {
    $service = new PaymentQrService(brokenSchemeRegistry());

    expect($service->payload(invoiceWithSkIban()))->toBeNull();
});

it('degrades to no PNG when the payload builder throws', function (): void {
    $service = new PaymentQrService(brokenSchemeRegistry());

    expect($service->png(invoiceWithSkIban()))->toBeNull();
});

it('turns a broken payload builder into a domain error on a supplier invoice', function (): void {
    $supplierInvoice = new SupplierInvoice;
    $supplierInvoice->supplier_invoice_number = 'FA-1';
    $supplierInvoice->variable_symbol = '1';
    $supplierInvoice->currency = Currency::EUR;
    $supplierInvoice->total = '120.00';
    $supplierInvoice->vendor_iban = 'SK3112000000198742637541';
    $supplierInvoice->vendor_snapshot = ['name' => 'Dodavatel s.r.o.'];

    $service = new SupplierPaymentQrService(brokenSchemeRegistry(), new CzechIbanConverter);

    expect(fn () => $service->payload($supplierInvoice))->toThrow(DomainException::class);
});
