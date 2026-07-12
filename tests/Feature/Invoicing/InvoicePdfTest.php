<?php

declare(strict_types=1);

use App\Modules\Auth\Domain\Models\User;
use App\Modules\Clients\Domain\Models\Client;
use App\Modules\Invoicing\Application\Services\InvoicePdfService;
use App\Modules\Invoicing\Domain\Models\BankAccount;
use App\Modules\Invoicing\Domain\Models\Invoice;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * @param  array<string, mixed>  $invoiceAttributes
 * @param  array<string, mixed>  $clientAttributes
 * @return array{0: User, 1: Invoice}
 */
function pdfScope(array $invoiceAttributes = [], array $clientAttributes = [], bool $withBank = true): array
{
    $user = createUser([
        'ico' => '12345678',
        'dic' => 'CZ12345678',
        'is_vat_payer' => true,
        'vat_status' => 'payer',
        'country' => 'CZ',
        'invoice_footer_text' => 'Děkujeme za spolupráci.',
    ]);

    $client = Client::factory()->create([
        'user_id' => $user->id,
        'client_type' => 'company',
        'company_name' => 'Testovacia s.r.o.',
        'country' => 'SK',
        'ico' => '87654321',
        'dic' => '2020202020',
        'vat_id' => 'SK2020202020',
        'is_vat_payer' => true,
        ...$clientAttributes,
    ]);

    $currency = $invoiceAttributes['currency'] ?? 'EUR';

    $bank = $withBank ? BankAccount::factory()->create([
        'user_id' => $user->id,
        'currency' => $currency,
        'iban' => 'CZ5855000000001265098001',
        'bic' => 'RZBCCZPP',
        'is_default' => true,
    ]) : null;

    $invoice = Invoice::factory()->draft()->create([
        'user_id' => $user->id,
        'client_id' => $client->id,
        'type' => 'invoice',
        'currency' => $currency,
        'bank_account_id' => $bank?->id,
        'variable_symbol' => '2026001',
        'exchange_rate_snapshot' => null,
        'discount_percent' => null,
        ...$invoiceAttributes,
    ]);

    $invoice->items()->create([
        'description' => 'Vývoj aplikácie',
        'quantity' => 10,
        'unit' => 'hod',
        'unit_price' => 50,
        'vat_rate' => 21,
        'vat_amount' => 105,
        'total_excl_vat' => 500,
        'total_incl_vat' => 605,
        'sort_order' => 0,
    ]);

    $invoice->refresh()->recalculateTotals()->save();

    return [$user, $invoice];
}

function renderInvoiceHtml(Invoice $invoice): string
{
    $service = app(InvoicePdfService::class);

    return view('invoices::pdf', ['vm' => $service->viewModel($invoice->loadMissing(
        ['client', 'items', 'user', 'bankAccount', 'relatedInvoice', 'workReportLines'],
    ))])->render();
}

it('downloads a PDF document', function (): void {
    [$user, $invoice] = pdfScope();

    $response = $this->actingAs($user)->get("/api/v1/invoices/{$invoice->id}/pdf/download");

    $response->assertOk();

    $content = $response->baseResponse instanceof StreamedResponse
        ? $response->streamedContent()
        : $response->getContent();

    expect(substr((string) $content, 0, 4))->toBe('%PDF');
});

it('prints the note above the items table and the note below it', function (): void {
    [, $invoice] = pdfScope([
        'note' => 'Poznámka pod položkami.',
        'note_above' => 'Vyúčtování za období 1.5.2026 – 31.5.2026',
    ]);

    $html = renderInvoiceHtml($invoice);

    expect($html)->toContain('Vyúčtování za období 1.5.2026 – 31.5.2026')
        ->toContain('Poznámka pod položkami.');

    $abovePosition = (int) strpos($html, 'Vyúčtování za období 1.5.2026 – 31.5.2026');
    $itemsPosition = (int) strpos($html, 'Vývoj aplikácie');
    $belowPosition = (int) strpos($html, 'Poznámka pod položkami.');

    expect($abovePosition)->toBeLessThan($itemsPosition)
        ->and($belowPosition)->toBeGreaterThan($itemsPosition);
});

it('prints Slovak client tax identifiers with native labels', function (): void {
    [, $invoice] = pdfScope();

    $html = renderInvoiceHtml($invoice);

    expect($html)->toContain('IČO: 87654321')
        ->toContain('DIČ: 2020202020')
        ->toContain('IČ DPH: SK2020202020');
});

it('hides IČ DPH for a non-VAT-payer client', function (): void {
    [, $invoice] = pdfScope(clientAttributes: ['is_vat_payer' => false, 'vat_id' => null]);

    $html = renderInvoiceHtml($invoice);

    expect($html)->toContain('IČO: 87654321')
        ->not->toContain('IČ DPH');
});

it('shows the grey CZK conversion table only for foreign currency with a rate', function (): void {
    [, $eurInvoice] = pdfScope(['currency' => 'EUR', 'exchange_rate_snapshot' => 25.0]);
    [, $czkInvoice] = pdfScope(['currency' => 'CZK']);

    expect(renderInvoiceHtml($eurInvoice))->toContain('class="czk-box"')
        ->and(renderInvoiceHtml($czkInvoice))->not->toContain('class="czk-box"');
});

it('embeds a payment QR for CZK and EUR but not without an IBAN', function (): void {
    [, $czkInvoice] = pdfScope(['currency' => 'CZK']);
    [, $eurInvoice] = pdfScope(['currency' => 'EUR']);
    [, $noBankInvoice] = pdfScope(withBank: false);

    expect(renderInvoiceHtml($czkInvoice))->toContain('data:image/svg+xml;base64')
        ->and(renderInvoiceHtml($eurInvoice))->toContain('data:image/svg+xml;base64')
        ->and(renderInvoiceHtml($noBankInvoice))->not->toContain('data:image/svg+xml;base64');
});

it('marks proformas as not being a tax document and hides DUZP', function (): void {
    [, $proforma] = pdfScope(['type' => 'proforma', 'taxable_supply_at' => null]);

    $html = renderInvoiceHtml($proforma);

    expect($html)->toContain('Proforma')
        ->toContain(__('invoices::pdf.not_tax_document'))
        ->not->toContain(__('invoices::pdf.taxable_supply_at'));
});

it('adds the work report page with an internal link when lines exist', function (): void {
    [, $invoice] = pdfScope();

    $invoice->workReportLines()->create([
        'work_date' => today()->toDateString(),
        'description' => 'Vícepráce',
        'hours' => 2,
        'sort_order' => 0,
    ]);

    $html = renderInvoiceHtml($invoice->refresh());

    expect($html)->toContain('href="#work-report"')
        ->toContain('name="work-report"');
});

it('omits the work report link without lines', function (): void {
    [, $invoice] = pdfScope();

    expect(renderInvoiceHtml($invoice))->not->toContain('href="#work-report"');
});

it('prints the supplier footer text and bank details', function (): void {
    [, $invoice] = pdfScope();

    $html = renderInvoiceHtml($invoice);

    expect($html)->toContain('Děkujeme za spolupráci.')
        ->toContain('CZ5855000000001265098001')
        ->toContain('2026001');
});
