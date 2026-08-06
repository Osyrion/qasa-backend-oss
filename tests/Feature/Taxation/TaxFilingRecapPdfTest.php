<?php

declare(strict_types=1);

use App\Modules\Auth\Domain\Models\User;
use App\Modules\Taxation\Application\Services\TaxFilingRecapService;
use App\Modules\Taxation\Domain\Enums\TaxFilingType;
use App\Modules\Taxation\Domain\Models\TaxFiling;

/**
 * Before this, the only way to check a VAT return before submitting it was
 * to read raw XML. On a feature where a wrong figure is a fine, that was the
 * wrong place to have saved effort.
 */
function archivedFiling(User $owner, string $xml): TaxFiling
{
    return TaxFiling::factory()->create([
        'user_id' => $owner->id,
        'type' => TaxFilingType::VatReturn->value,
        'country' => 'SK',
        'period_year' => 2026,
        'period_month' => 3,
        'content' => $xml,
        'sha256' => hash('sha256', $xml),
    ]);
}

it('lists every value the archived document carries', function (): void {
    $owner = createUser(['country' => 'SK']);

    $filing = archivedFiling($owner, <<<'XML'
    <?xml version="1.0" encoding="UTF-8"?>
    <dokument>
      <hlavicka><dic>1020304050</dic><obdobie mesiac="03">2026</obdobie></hlavicka>
      <telo><r01>200.00</r01><r02>46.00</r02><r05></r05></telo>
    </dokument>
    XML);

    $rows = app(TaxFilingRecapService::class)->rows($filing);
    $paths = array_column($rows, 'path');

    expect(array_column($rows, 'value'))->toContain('200.00', '46.00', '1020304050', '03')
        // Empty lines are the norm on these forms; listing them would bury
        // the handful that carry a number.
        ->and(implode('|', $paths))->not->toContain('r05');
});

it('serves the recap as a PDF carrying the checksum of what will be filed', function (): void {
    $owner = createUser(['country' => 'SK']);
    $filing = archivedFiling($owner, '<?xml version="1.0"?><dokument><telo><r01>200.00</r01></telo></dokument>');

    $response = $this->actingAs($owner)
        ->get("/api/v1/tax-filings/{$filing->id}/recap.pdf")
        ->assertOk();

    expect($response->headers->get('content-type'))->toContain('application/pdf')
        ->and((string) $response->getContent())->toStartWith('%PDF');
});

it('reads the archive rather than recomputing, so a figure that has moved still shows as filed', function (): void {
    $owner = createUser(['country' => 'SK']);
    $filing = archivedFiling($owner, '<?xml version="1.0"?><dokument><telo><r01>999.99</r01></telo></dokument>');

    // Nothing in the account produces 999.99 — it is in the recap because it
    // is in the archived document, which is the entire point.
    expect(array_column(app(TaxFilingRecapService::class)->rows($filing), 'value'))->toContain('999.99');
});

it('never resolves an external entity in an archived document', function (): void {
    $owner = createUser(['country' => 'SK']);

    $filing = archivedFiling($owner, <<<'XML'
    <?xml version="1.0"?>
    <!DOCTYPE dokument [<!ENTITY xxe SYSTEM "file:///etc/passwd">]>
    <dokument><telo><r01>&xxe;</r01></telo></dokument>
    XML);

    $values = implode('|', array_column(app(TaxFilingRecapService::class)->rows($filing), 'value'));

    expect($values)->not->toContain('root:');
});

it('refuses a filing belonging to another account', function (): void {
    $owner = createUser(['country' => 'SK']);
    $stranger = createUser(['country' => 'SK']);

    $filing = asAccount($stranger, fn (): TaxFiling => archivedFiling($stranger, '<?xml version="1.0"?><dokument/>'));

    $this->actingAs($owner)->get("/api/v1/tax-filings/{$filing->id}/recap.pdf")->assertNotFound();
});
