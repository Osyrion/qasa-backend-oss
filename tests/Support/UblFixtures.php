<?php

declare(strict_types=1);

use App\Modules\Auth\Domain\Models\User;
use App\Modules\Clients\Domain\Models\Client;
use App\Modules\Invoicing\Domain\Models\Invoice;
use App\Modules\Invoicing\Domain\Models\InvoiceItem;
use App\Modules\Invoicing\Infrastructure\Ubl\Ubl21InvoiceBuilder;

/*
 * Shared by UblEInvoiceTest (XSD + business terms) and UblSchematronTest
 * (the official EN 16931 rules) — the same invoice has to satisfy both, so
 * they build it the same way.
 */

/**
 * @param  array<string, mixed>  $attributes
 * @param  float|list<float>  $vatRate  A list builds one line per rate, which
 *                                      is what a document-level discount has
 *                                      to be split across (BR-S-08)
 * @param  array<string, mixed>  $clientAttributes  Overrides on the buyer —
 *                                                  `peppol_id`, mostly, which
 *                                                  is what makes a document
 *                                                  routable
 */
function ublInvoice(array $attributes = [], float|array $vatRate = 23.0, ?User $owner = null, array $clientAttributes = []): Invoice
{
    $owner ??= createUser(['country' => 'SK']);
    $client = Client::factory()->create(['user_id' => $owner->id, ...$clientAttributes]);

    $invoice = Invoice::factory()->create([
        'user_id' => $owner->id,
        'client_id' => $client->id,
        'invoice_number' => 'FA-2026-001',
        'currency' => 'EUR',
        'supplier_snapshot' => [
            'name' => 'Dodávateľ s.r.o.',
            'ico' => '12345678',
            'dic' => '1020304050',
            'vat_id' => 'SK1020304050',
            'is_vat_payer' => true,
            'address' => 'Hlavná 1',
            'city' => 'Bratislava',
            'postal_code' => '81101',
            'country' => 'SK',
        ],
        'client_snapshot' => [
            'name' => 'Odberateľ a.s.',
            'ico' => '87654321',
            'dic' => '9080706050',
            'vat_id' => 'SK9080706050',
            'address' => 'Vedľajšia 2',
            'city' => 'Košice',
            'postal_code' => '04001',
            'country' => 'SK',
        ],
        'bank_account_snapshot' => [
            'iban' => 'SK3112000000198742637541',
            'bic' => 'TATRSKBX',
            'bank_name' => 'Tatra banka',
            'account_number' => '198742637541/1200',
        ],
        'variable_symbol' => '2026001',
        ...$attributes,
    ]);

    // The factory derives vat_amount/total_* from its own random quantity
    // and price, so overriding only the inputs would leave the line
    // internally inconsistent — and the round-trip assertions below would
    // compare two different invoices.
    foreach (is_array($vatRate) ? $vatRate : [$vatRate] as $rate) {
        $base = 200.0;
        $vat = round($base * $rate / 100, 2);

        InvoiceItem::factory()->create([
            'invoice_id' => $invoice->id,
            'description' => 'Konzultácie',
            'quantity' => 2,
            'unit' => 'hod',
            'unit_price' => 100,
            'vat_rate' => $rate,
            'vat_amount' => $vat,
            'total_excl_vat' => $base,
            'total_incl_vat' => $base + $vat,
        ]);
    }

    // The factory seeds its own header totals; recompute them from the item
    // actually attached above, so "the export equals what the invoice holds"
    // is a real assertion rather than two reads of the same stale number.
    // recalculateTotals() only sets the attributes — saving is the caller's
    // job, and a fresh() before that would throw them away.
    $invoice = $invoice->fresh(['items']);
    assert($invoice !== null);
    $invoice->recalculateTotals()->save();

    $invoice = $invoice->fresh(['items']);
    assert($invoice !== null);

    return $invoice;
}

function buildUbl(Invoice $invoice): string
{
    $fresh = $invoice->fresh(['items', 'relatedInvoice']);
    assert($fresh !== null);

    return app(Ubl21InvoiceBuilder::class)->build($fresh);
}

/**
 * A received e-invoice, hand-written rather than exported: the receiving side
 * has to cope with what a supplier's system emits, which is never our own
 * builder's output. Shared by the inbox tests and the inbound-email ones.
 */
function minimalUbl(string $number = 'DODA-1', string $ico = '12345678'): string
{
    return <<<XML
    <?xml version="1.0" encoding="UTF-8"?>
    <Invoice xmlns="urn:oasis:names:specification:ubl:schema:xsd:Invoice-2"
             xmlns:cac="urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2"
             xmlns:cbc="urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2">
      <cbc:ID>{$number}</cbc:ID>
      <cbc:IssueDate>2026-03-01</cbc:IssueDate>
      <cbc:DueDate>2026-03-15</cbc:DueDate>
      <cbc:DocumentCurrencyCode>EUR</cbc:DocumentCurrencyCode>
      <cac:AccountingSupplierParty><cac:Party>
        <cac:PartyLegalEntity><cbc:CompanyID>{$ico}</cbc:CompanyID></cac:PartyLegalEntity>
      </cac:Party></cac:AccountingSupplierParty>
      <cac:PaymentMeans>
        <cbc:PaymentID>7788</cbc:PaymentID>
        <cac:PayeeFinancialAccount><cbc:ID>SK3112000000198742637541</cbc:ID></cac:PayeeFinancialAccount>
      </cac:PaymentMeans>
      <cac:TaxTotal>
        <cbc:TaxAmount currencyID="EUR">46.00</cbc:TaxAmount>
        <cac:TaxSubtotal>
          <cbc:TaxableAmount currencyID="EUR">200.00</cbc:TaxableAmount>
          <cbc:TaxAmount currencyID="EUR">46.00</cbc:TaxAmount>
          <cac:TaxCategory><cbc:ID>S</cbc:ID><cbc:Percent>23.00</cbc:Percent></cac:TaxCategory>
        </cac:TaxSubtotal>
      </cac:TaxTotal>
      <cac:LegalMonetaryTotal><cbc:TaxInclusiveAmount currencyID="EUR">246.00</cbc:TaxInclusiveAmount></cac:LegalMonetaryTotal>
      <cac:InvoiceLine>
        <cbc:ID>1</cbc:ID>
        <cbc:LineExtensionAmount currencyID="EUR">200.00</cbc:LineExtensionAmount>
        <cac:Item><cbc:Name>Služby</cbc:Name></cac:Item>
      </cac:InvoiceLine>
    </Invoice>
    XML;
}
