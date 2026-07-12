<?php

declare(strict_types=1);

use App\Modules\Invoicing\Domain\Models\PaymentOrder;
use App\Modules\Invoicing\Domain\Models\PaymentOrderItem;
use App\Modules\Invoicing\Domain\Services\AboKpcBuilder;
use App\Modules\Shared\Enums\Currency;
use App\Modules\Shared\Exceptions\DomainException;

function goldenAboOrder(): PaymentOrder
{
    $order = PaymentOrder::factory()->create([
        'payer_snapshot' => [
            'label' => 'Hlavní účet',
            'bank_name' => 'KB',
            'account_number' => '123456789/0100',
            'iban' => null,
            'bic' => null,
            'currency' => 'CZK',
        ],
        'currency' => Currency::CZK->value,
        'due_date' => '2026-07-21',
        'constant_symbol' => '0308',
        'items_count' => 2,
        'total_amount' => 1734.56,
    ]);

    PaymentOrderItem::factory()->create([
        'payment_order_id' => $order->id,
        'vendor_name' => 'Kancelářské potřeby s.r.o.',
        'account_number' => '19-2000145399',
        'bank_code' => '0800',
        'variable_symbol' => '20260001',
        'amount' => 1234.56,
        'sort_order' => 0,
    ]);

    PaymentOrderItem::factory()->create([
        'payment_order_id' => $order->id,
        'vendor_name' => 'Druhý dodavatel',
        'account_number' => '35-7758010287',
        'bank_code' => '0100',
        'variable_symbol' => null,
        'amount' => 500.00,
        'sort_order' => 1,
    ]);

    return $order->load('items');
}

it('builds a byte-identical golden ABO (KPC) file', function (): void {
    $this->travelTo('2026-07-20 10:00:00');

    $output = app(AboKpcBuilder::class)->build(goldenAboOrder());

    // Byte-by-byte: the format is positional, a diff means a regression.
    expect($output)->toBe((string) file_get_contents(base_path('tests/Fixtures/payment-order/hromadny-prikaz.kpc')));
});

it('refuses a batch with an IBAN-only row', function (): void {
    $order = goldenAboOrder();

    $order->items->first()?->update([
        'account_number' => null,
        'bank_code' => null,
        'iban' => 'CZ6508000000192000145399',
    ]);

    app(AboKpcBuilder::class)->build($order->load('items'));
})->throws(DomainException::class);

it('refuses a non-CZK batch', function (): void {
    $order = goldenAboOrder();
    $order->update(['currency' => Currency::EUR->value]);

    app(AboKpcBuilder::class)->build($order->refresh()->load('items'));
})->throws(DomainException::class);
