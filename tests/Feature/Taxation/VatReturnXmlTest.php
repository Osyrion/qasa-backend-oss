<?php

declare(strict_types=1);

use App\Modules\Auth\Domain\Models\User;
use App\Modules\Clients\Domain\Models\Client;
use App\Modules\Invoicing\Application\DTOs\VatReturnReportData;
use App\Modules\Taxation\Infrastructure\Sk\DphXmlBuilder;

/**
 * @return array{0: bool, 1: list<string>}
 */
function vrValidateXsd(string $xml, string $xsdPath): array
{
    libxml_use_internal_errors(true);

    $dom = new DOMDocument;
    $dom->loadXML($xml);
    $valid = $dom->schemaValidate($xsdPath);

    $errors = array_map(static fn ($e): string => trim((string) $e->message), libxml_get_errors());
    libxml_clear_errors();

    return [$valid, $errors];
}

/**
 * @return array<string, string>
 */
function vrRows(string $xml): array
{
    $dom = new DOMDocument;
    $dom->loadXML($xml);
    $telo = $dom->getElementsByTagName('telo')->item(0);

    $rows = [];

    if ($telo !== null) {
        foreach ($telo->childNodes as $node) {
            if ($node instanceof DOMElement) {
                $rows[$node->tagName] = $node->textContent;
            }
        }
    }

    return $rows;
}

it('generates a schema-valid SK DPHv25 XML draft with the expected row totals', function (): void {
    [$user, $client23] = vcsScope('SK');
    $user->update(['vat_id' => 'SK1234567890', 'ico' => '12345678']);
    $client23->update(['vat_id' => 'SK2020202020', 'is_vat_payer' => true]);

    // r01/r02 — domestic output at 23%.
    vcsIssueInvoice($this, $user, $client23, '2027-03-05', 1000, 23);

    // r01a/r02a — domestic output at 10%.
    $client10 = Client::factory()->create(['user_id' => $user->id, 'country' => 'SK']);
    vcsIssueInvoice($this, $user, $client10, '2027-03-06', 500, 10);

    // r11/r11a — EU acquisition (self-assessed).
    $euVendor = Client::factory()->vendor()->create(['user_id' => $user->id, 'country' => 'DE', 'vat_id' => 'DE555666777', 'is_vat_payer' => true]);
    $eu = $this->actingAs($user)->postJson('/api/v1/supplier-invoices', supplierInvoicePayload($euVendor->id, [
        'issued_at' => '2027-03-07', 'vat_regime' => 'eu_reverse_charge',
        'vat_lines' => [['vat_rate' => 23, 'base' => 400, 'vat_amount' => 92]],
    ]))->assertCreated();
    $this->actingAs($user)->postJson("/api/v1/supplier-invoices/{$eu->json('data.id')}/status", ['status' => 'received'])->assertOk();

    // r12/r12a — import (self-assessed).
    $importVendor = Client::factory()->vendor()->create(['user_id' => $user->id, 'country' => 'US']);
    $import = $this->actingAs($user)->postJson('/api/v1/supplier-invoices', supplierInvoicePayload($importVendor->id, [
        'issued_at' => '2027-03-08', 'vat_regime' => 'import',
        'vat_lines' => [['vat_rate' => 23, 'base' => 300, 'vat_amount' => 69]],
    ]))->assertCreated();
    $this->actingAs($user)->postJson("/api/v1/supplier-invoices/{$import->json('data.id')}/status", ['status' => 'received'])->assertOk();

    // r16 — domestic deductible input.
    $domesticVendor = Client::factory()->vendor()->create(['user_id' => $user->id, 'country' => 'SK', 'vat_id' => 'SK4040404040', 'is_vat_payer' => true]);
    $domestic = $this->actingAs($user)->postJson('/api/v1/supplier-invoices', supplierInvoicePayload($domesticVendor->id, [
        'issued_at' => '2027-03-09',
        'vat_lines' => [['vat_rate' => 23, 'base' => 200, 'vat_amount' => 46]],
    ]))->assertCreated();
    $this->actingAs($user)->postJson("/api/v1/supplier-invoices/{$domestic->json('data.id')}/status", ['status' => 'received'])->assertOk();

    $response = $this->actingAs($user)->postJson('/api/v1/tax-filings', [
        'type' => 'vat_return', 'period_year' => 2027, 'period_month' => 3,
    ])->assertStatus(201);

    expect($response->json('data.type'))->toBe('vat_return')
        ->and($response->json('data.country'))->toBe('SK');

    $xml = (string) $response->json('data.content');

    expect($xml)->toContain('<dokument>');

    [$valid, $errors] = vrValidateXsd($xml, base_path('tests/Fixtures/vat-return/dph2025.xsd'));
    expect($errors)->toBeEmpty();
    expect($valid)->toBeTrue();

    $rows = vrRows($xml);

    expect($rows['r01'])->toBe('1000.00')
        ->and($rows['r02'])->toBe('230.00')
        ->and($rows['r01a'])->toBe('500.00')
        ->and($rows['r02a'])->toBe('50.00')
        ->and($rows['r03'])->toBe('')
        ->and($rows['r04'])->toBe('')
        ->and($rows['r11'])->toBe('400.00')
        ->and($rows['r11a'])->toBe('92.00')
        ->and($rows['r12'])->toBe('300.00')
        ->and($rows['r12a'])->toBe('69.00')
        // r15 = 230 + 50 + 92 + 69
        ->and($rows['r15'])->toBe('441.00')
        ->and($rows['r16'])->toBe('46.00')
        // r17 = 92 + 69 (self-assessed, immediately deductible)
        ->and($rows['r17'])->toBe('161.00')
        // r19 = 46 + 161
        ->and($rows['r19'])->toBe('207.00')
        // r20 (vlastná daňová povinnosť) = r15 - r19 = 441 - 207
        ->and($rows['r20'])->toBe('234.00')
        ->and($rows['r21'])->toBe('');
});

it('rejects an annual-scope SK VAT return — requires a month or quarter', function (): void {
    [$user] = vcsScope('SK');

    $this->actingAs($user)->postJson('/api/v1/tax-filings', [
        'type' => 'vat_return', 'period_year' => 2027,
    ])->assertStatus(422);
});

it('produces an excess deduction (nadmerný odpočet) at r21 when input exceeds output', function (): void {
    [$user, $client] = vcsScope('SK');

    // Small output.
    vcsIssueInvoice($this, $user, $client, '2027-03-05', 100, 23);

    // Large domestic deductible input.
    $vendor = Client::factory()->vendor()->create(['user_id' => $user->id, 'country' => 'SK']);
    $supplier = $this->actingAs($user)->postJson('/api/v1/supplier-invoices', supplierInvoicePayload($vendor->id, [
        'issued_at' => '2027-03-06',
        'vat_lines' => [['vat_rate' => 23, 'base' => 5000, 'vat_amount' => 1150]],
    ]))->assertCreated();
    $this->actingAs($user)->postJson("/api/v1/supplier-invoices/{$supplier->json('data.id')}/status", ['status' => 'received'])->assertOk();

    $response = $this->actingAs($user)->postJson('/api/v1/tax-filings', [
        'type' => 'vat_return', 'period_year' => 2027, 'period_month' => 3,
    ])->assertStatus(201);

    $rows = vrRows((string) $response->json('data.content'));

    expect($rows['r20'])->toBe('')
        ->and($rows['r21'])->toBe('1127.00');
});

it('builds a byte-structurally-valid golden XML from a directly constructed report', function (): void {
    $user = User::factory()->make([
        'title' => null, 'name' => 'Ján', 'surname' => 'Novák',
        'email' => 'jan.novak@example.sk', 'phone' => '+421900123456',
        'ico' => '12345678', 'dic' => '1234567890', 'vat_id' => 'SK1234567890', 'country' => 'SK',
        'address' => 'Hlavná 1', 'city' => 'Bratislava', 'postal_code' => '81101',
    ]);

    $report = new VatReturnReportData(
        country: 'SK',
        year: 2027,
        month: 3,
        quarter: null,
        outputByRate: ['23' => ['base' => 1000.0, 'vat' => 230.0]],
        euAcquisitionBase: 0.0,
        euAcquisitionVat: 0.0,
        importBase: 0.0,
        importVat: 0.0,
        domesticInputBase: 0.0,
        domesticInputVat: 0.0,
        assumptions: [],
    );

    $xml = app(DphXmlBuilder::class)->build($report, $user);

    [$valid, $errors] = vrValidateXsd($xml, base_path('tests/Fixtures/vat-return/dph2025.xsd'));
    expect($errors)->toBeEmpty();
    expect($valid)->toBeTrue();

    $rows = vrRows($xml);
    expect($rows['r01'])->toBe('1000.00')
        ->and($rows['r02'])->toBe('230.00')
        ->and($rows['r15'])->toBe('230.00')
        ->and($rows['r20'])->toBe('230.00');
});
