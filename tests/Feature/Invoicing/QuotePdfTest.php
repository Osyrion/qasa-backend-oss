<?php

declare(strict_types=1);

use App\Modules\Auth\Domain\Models\User;
use App\Modules\Clients\Domain\Models\Client;
use App\Modules\Invoicing\Application\Services\QuotePdfService;
use App\Modules\Invoicing\Domain\Models\Quote;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * @param  array<string, mixed>  $quoteAttributes
 * @return array{0: User, 1: Quote}
 */
function quotePdfScope(array $quoteAttributes = []): array
{
    $user = createUser(['country' => 'SK']);
    $client = Client::factory()->create(['user_id' => $user->id, 'locale' => 'sk']);

    $quote = Quote::factory()->create([
        'user_id' => $user->id,
        'client_id' => $client->id,
        'currency' => 'EUR',
        ...$quoteAttributes,
    ]);

    $quote->items()->create([
        'description' => 'Konzultačné služby', 'quantity' => 1, 'unit' => 'ks',
        'unit_price' => 500, 'vat_rate' => 20, 'vat_amount' => 100,
        'total_excl_vat' => 500, 'total_incl_vat' => 600, 'sort_order' => 0,
    ]);
    $quote->load('items')->recalculateTotals()->save();

    return [$user, $quote->refresh()];
}

function renderQuoteHtml(Quote $quote): string
{
    $service = app(QuotePdfService::class);

    return view('invoices::quote-pdf', ['vm' => $service->viewModel($quote->loadMissing(['client', 'items', 'user']))])->render();
}

it('downloads a PDF document', function (): void {
    [$user, $quote] = quotePdfScope();

    $response = $this->actingAs($user)->get("/api/v1/quotes/{$quote->id}/pdf/download");

    $response->assertOk();

    $content = $response->baseResponse instanceof StreamedResponse
        ? $response->streamedContent()
        : $response->getContent();

    expect(substr((string) $content, 0, 4))->toBe('%PDF');
});

it('prints "not a tax document" and no QR/bank details', function (): void {
    [, $quote] = quotePdfScope();

    $html = renderQuoteHtml($quote);

    expect($html)->toContain(__('invoices::pdf.not_tax_document'))
        ->not->toContain('IBAN')
        ->not->toContain('BIC')
        ->not->toContain('data:image/svg+xml;base64');
});

it('shows the VAT recap and item lines', function (): void {
    [, $quote] = quotePdfScope();

    $html = renderQuoteHtml($quote);

    expect($html)->toContain('Konzultačné služby')
        ->toContain(__('invoices::pdf.vat_recap'));
});

it('localizes the PDF to the client locale', function (): void {
    [, $quote] = quotePdfScope();
    $quote->client?->update(['locale' => 'en']);

    App::setLocale('en');
    $html = renderQuoteHtml($quote->refresh());
    App::setLocale('sk');

    expect($html)->toContain('Not a tax document');
});
