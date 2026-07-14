<?php

declare(strict_types=1);

use App\Modules\Auth\Domain\Models\User;
use App\Modules\Clients\Domain\Models\Client;
use App\Modules\Invoicing\Domain\Models\Invoice;
use App\Modules\Invoicing\Domain\Services\PohodaXmlBuilder;

/**
 * @param  array<string, mixed>  $invoiceAttributes
 * @return array{0: User, 1: Invoice}
 */
function xmlExportInvoice(array $invoiceAttributes = []): array
{
    $user = createUser([
        'ico' => '12345678',
        'dic' => '2020202020',
        'vat_id' => 'SK2020202020',
        'is_vat_payer' => true,
        'vat_status' => 'payer',
        'country' => 'SK',
        'address' => 'Hlavná 1',
        'city' => 'Bratislava',
        'postal_code' => '81101',
    ]);

    $client = Client::factory()->create([
        'user_id' => $user->id,
        'client_type' => 'company',
        'company_name' => 'Klient s.r.o.',
        'country' => 'SK',
        'ico' => '87654321',
        'dic' => '3030303030',
        'vat_id' => 'SK3030303030',
        'is_vat_payer' => true,
    ]);

    $currency = $invoiceAttributes['currency'] ?? 'EUR';

    $invoice = Invoice::factory()->sent()->create([
        'user_id' => $user->id,
        'client_id' => $client->id,
        'type' => 'invoice',
        'currency' => $currency,
        'variable_symbol' => '2026001',
        'exchange_rate_snapshot' => $currency === 'CZK' ? null : 25.0,
        'discount_percent' => null,
        'taxable_supply_at' => now()->toDateString(),
        'supplier_snapshot' => [
            'name' => $user->full_name,
            'ico' => $user->ico,
            'dic' => $user->dic,
            'vat_id' => $user->vat_id,
            'is_vat_payer' => $user->is_vat_payer,
            'address' => $user->address,
            'city' => $user->city,
            'postal_code' => $user->postal_code,
            'country' => $user->country,
        ],
        'client_snapshot' => [
            'name' => $client->display_name,
            'ico' => $client->ico,
            'dic' => $client->dic,
            'vat_id' => $client->vat_id,
            'is_vat_payer' => $client->is_vat_payer,
            'address' => $client->address,
            'city' => $client->city,
            'postal_code' => $client->postal_code,
            'country' => $client->country,
        ],
        ...$invoiceAttributes,
    ]);

    $invoice->items()->create([
        'description' => 'Konzultácia',
        'quantity' => 10,
        'unit' => 'hod',
        'unit_price' => 50,
        'vat_rate' => 23,
        'vat_amount' => 115,
        'total_excl_vat' => 500,
        'total_incl_vat' => 615,
        'sort_order' => 0,
    ]);

    $invoice->refresh()->recalculateTotals()->save();

    return [$user, $invoice->refresh()];
}

it('produces well-formed XML with one invoice element per invoice', function (): void {
    [, $invoice] = xmlExportInvoice();

    $xml = app(PohodaXmlBuilder::class)->build([$invoice]);

    libxml_use_internal_errors(true);
    $dom = new DOMDocument;
    $loaded = $dom->loadXML($xml);
    $errors = libxml_get_errors();
    libxml_clear_errors();

    expect($loaded)->toBeTrue()
        ->and($errors)->toBeEmpty()
        ->and(substr_count($xml, '<inv:invoice '))->toBe(1)
        ->and($xml)->toContain('<inv:dateTax>')
        ->and($xml)->toContain('<inv:symVar>2026001</inv:symVar>');
});

it('maps VAT rate categories into the invoice item and the summary bucket amounts', function (): void {
    [, $invoice] = xmlExportInvoice(['currency' => 'CZK', 'exchange_rate_snapshot' => null]);

    $xml = app(PohodaXmlBuilder::class)->build([$invoice]);

    expect($xml)->toContain('<inv:rateVAT>high</inv:rateVAT>')
        ->toContain('<inv:priceHigh>500.00</inv:priceHigh>')
        ->toContain('<inv:priceHighVAT>115.00</inv:priceHighVAT>')
        ->toContain('<inv:priceHighSum>615.00</inv:priceHighSum>');
});

it('includes a foreignCurrency block converted via the exchange rate for non-CZK invoices', function (): void {
    [, $invoice] = xmlExportInvoice(['currency' => 'EUR', 'exchange_rate_snapshot' => 25.0]);

    $xml = app(PohodaXmlBuilder::class)->build([$invoice]);

    expect($xml)->toContain('<inv:foreignCurrency')
        ->toContain('<typ:ids>EUR</typ:ids>')
        ->toContain('<inv:rate>25.000000</inv:rate>');

    $foreignStart = (int) strpos($xml, '<inv:foreignCurrency');
    $foreignBlock = substr($xml, $foreignStart);
    expect($foreignBlock)->toContain('<inv:priceHigh>500.00</inv:priceHigh>');

    $homeStart = (int) strpos($xml, '<inv:homeCurrency>');
    $homeEnd = (int) strpos($xml, '</inv:homeCurrency>');
    $homeBlock = substr($xml, $homeStart, $homeEnd - $homeStart);
    expect($homeBlock)->toContain('<inv:priceHigh>12500.00</inv:priceHigh>');
});

it('omits foreignCurrency for CZK invoices', function (): void {
    [, $invoice] = xmlExportInvoice(['currency' => 'CZK', 'exchange_rate_snapshot' => null]);

    $xml = app(PohodaXmlBuilder::class)->build([$invoice]);

    expect($xml)->not->toContain('<inv:foreignCurrency>');
});

it('sets the dataPack ico from the first invoice supplier snapshot', function (): void {
    [, $invoice] = xmlExportInvoice();

    $xml = app(PohodaXmlBuilder::class)->build([$invoice]);

    expect($xml)->toContain('ico="12345678"');
});

it('produces a valid empty dataPack for no invoices', function (): void {
    $xml = app(PohodaXmlBuilder::class)->build([]);

    libxml_use_internal_errors(true);
    $dom = new DOMDocument;
    $loaded = $dom->loadXML($xml);
    libxml_clear_errors();

    expect($loaded)->toBeTrue()
        ->and($xml)->not->toContain('<inv:invoice ');
});

it('appends the domestic reverse charge clause to the note element', function (): void {
    [, $invoice] = xmlExportInvoice([
        'reverse_charge' => true,
        'reverse_charge_mode' => 'domestic',
    ]);

    $xml = app(PohodaXmlBuilder::class)->build([$invoice]);

    expect($xml)->toContain((string) __('invoices::pdf.reverse_charge_clause_domestic_sk'));

    libxml_use_internal_errors(true);
    $dom = new DOMDocument;
    $loaded = $dom->loadXML($xml);
    libxml_clear_errors();
    expect($loaded)->toBeTrue();
});

it('appends the EU reverse charge clause to the note element', function (): void {
    [, $invoice] = xmlExportInvoice([
        'reverse_charge' => true,
        'reverse_charge_mode' => 'eu',
    ]);

    $xml = app(PohodaXmlBuilder::class)->build([$invoice]);

    expect($xml)->toContain((string) __('invoices::pdf.reverse_charge_clause_eu'));
});

it('exports a non-VAT-payer invoice with all-zero rates without a reverse charge clause', function (): void {
    $user = createUser([
        'ico' => '12345678', 'country' => 'SK', 'vat_status' => 'non_payer', 'is_vat_payer' => false,
    ]);
    $client = Client::factory()->create(['user_id' => $user->id, 'country' => 'SK']);

    $invoice = Invoice::factory()->sent()->create([
        'user_id' => $user->id,
        'client_id' => $client->id,
        'type' => 'invoice',
        'currency' => 'EUR',
        'discount_percent' => null,
        'supplier_snapshot' => ['name' => $user->full_name, 'country' => 'SK', 'vat_status' => 'non_payer'],
        'client_snapshot' => ['name' => $client->display_name, 'country' => 'SK'],
    ]);

    $invoice->items()->create([
        'description' => 'Konzultácia', 'quantity' => 1, 'unit' => 'ks',
        'unit_price' => 100, 'vat_rate' => 0, 'vat_amount' => 0,
        'total_excl_vat' => 100, 'total_incl_vat' => 100, 'sort_order' => 0,
    ]);

    $invoice->refresh()->recalculateTotals()->save();

    $xml = app(PohodaXmlBuilder::class)->build([$invoice->refresh()]);

    libxml_use_internal_errors(true);
    $dom = new DOMDocument;
    $loaded = $dom->loadXML($xml);
    libxml_clear_errors();

    expect($loaded)->toBeTrue()
        ->and($xml)->toContain('<inv:rateVAT>none</inv:rateVAT>')
        ->and($xml)->not->toContain((string) __('invoices::pdf.reverse_charge_clause_domestic_sk'))
        ->and($xml)->not->toContain((string) __('invoices::pdf.reverse_charge_clause_eu'));
});
