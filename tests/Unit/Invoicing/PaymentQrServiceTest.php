<?php

declare(strict_types=1);

use App\Modules\Invoicing\Application\Services\PaymentQrService;
use App\Modules\Invoicing\Domain\Models\Invoice;
use App\Modules\Invoicing\Domain\Services\EpcQrBuilder;
use App\Modules\Invoicing\Domain\Services\SpaydBuilder;

function qrService(): PaymentQrService
{
    return new PaymentQrService(new SpaydBuilder, new EpcQrBuilder);
}

/**
 * @param  array<string, mixed>  $attributes
 */
function czkInvoice(array $attributes = []): Invoice
{
    return Invoice::factory()->create(array_merge([
        'status' => 'sent',
        'currency' => 'CZK',
        'total' => 1000,
        'variable_symbol' => '2026001',
        'bank_account_snapshot' => [
            'label' => 'CZK účet',
            'bank_name' => 'Raiffeisenbank',
            'account_number' => null,
            'iban' => 'CZ5855000000001265098001',
            'bic' => 'RZBCCZPP',
            'currency' => 'CZK',
        ],
    ], $attributes));
}

it('encodes the invoice total by default and an override amount when given', function (): void {
    $invoice = czkInvoice();

    expect(qrService()->payload($invoice))->toContain('AM:1000.00')
        ->and(qrService()->payload($invoice, 123.45))->toContain('AM:123.45');
});

it('renders a binary PNG payment QR', function (): void {
    if (! extension_loaded('imagick') && ! extension_loaded('gd')) {
        $this->markTestSkipped('Neither imagick nor gd is available for PNG QR rendering.');
    }

    $png = qrService()->png(czkInvoice());

    expect($png)->not->toBeNull()
        ->and(substr((string) $png, 0, 4))->toBe("\x89PNG");
});

it('returns no QR when the bank account has no IBAN', function (): void {
    $invoice = czkInvoice([
        'bank_account_snapshot' => ['label' => 'Bez IBANu', 'iban' => null],
        'bank_account_id' => null,
    ]);

    expect(qrService()->payload($invoice))->toBeNull()
        ->and(qrService()->png($invoice))->toBeNull();
});
