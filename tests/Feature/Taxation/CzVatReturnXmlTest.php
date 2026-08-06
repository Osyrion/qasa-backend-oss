<?php

declare(strict_types=1);

use App\Modules\Auth\Domain\Models\User;
use App\Modules\Clients\Domain\Models\Client;
use App\Modules\Invoicing\Application\DTOs\VatReturnReportData;
use App\Modules\Taxation\Infrastructure\Cz\DphDp3XmlBuilder;

/**
 * @return array{0: bool, 1: list<string>}
 */
function czVrValidateXsd(string $xml, string $xsdPath): array
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
 * @return array<string, ?string>
 */
function czVrAttrs(string $xml, string $tag): array
{
    $dom = new DOMDocument;
    $dom->loadXML($xml);
    $node = $dom->getElementsByTagName($tag)->item(0);

    if ($node === null) {
        throw new RuntimeException("No <{$tag}> element found in the generated XML.");
    }

    $attrs = [];

    foreach ($node->attributes as $attr) {
        $attrs[$attr->nodeName] = $attr->nodeValue;
    }

    return $attrs;
}

it('generates a schema-valid CZ DPH3 XML draft with the expected totals', function (): void {
    [$user, $client21] = vcsScope('CZ');
    $user->update(['dic' => '12345678']);
    $client21->update(['dic' => '87654321', 'is_vat_payer' => true]);

    // tier 1 (21 %) domestic output.
    vcsIssueInvoice($this, $user, $client21, '2027-03-05', 1000, 21);

    // tier 2 (12 %) domestic output.
    $client12 = Client::factory()->create(['user_id' => $user->id, 'country' => 'CZ']);
    vcsIssueInvoice($this, $user, $client12, '2027-03-06', 500, 12);

    // EU acquisition (self-assessed).
    $euVendor = Client::factory()->vendor()->create(['user_id' => $user->id, 'country' => 'DE', 'dic' => '555666777']);
    $eu = $this->actingAs($user)->postJson('/api/v1/supplier-invoices', supplierInvoicePayload($euVendor->id, [
        'issued_at' => '2027-03-07', 'currency' => 'CZK', 'vat_regime' => 'eu_reverse_charge',
        'vat_lines' => [['vat_rate' => 21, 'base' => 400, 'vat_amount' => 84]],
    ]))->assertCreated();
    $this->actingAs($user)->postJson("/api/v1/supplier-invoices/{$eu->json('data.id')}/status", ['status' => 'received'])->assertOk();

    // Domestic deductible input.
    $domesticVendor = Client::factory()->vendor()->create(['user_id' => $user->id, 'country' => 'CZ', 'dic' => '99887766']);
    $domestic = $this->actingAs($user)->postJson('/api/v1/supplier-invoices', supplierInvoicePayload($domesticVendor->id, [
        'issued_at' => '2027-03-08', 'currency' => 'CZK',
        'vat_lines' => [['vat_rate' => 21, 'base' => 200, 'vat_amount' => 42]],
    ]))->assertCreated();
    $this->actingAs($user)->postJson("/api/v1/supplier-invoices/{$domestic->json('data.id')}/status", ['status' => 'received'])->assertOk();

    $response = $this->actingAs($user)->postJson('/api/v1/tax-filings', [
        'type' => 'vat_return', 'period_year' => 2027, 'period_month' => 3,
    ])->assertStatus(201);

    expect($response->json('data.country'))->toBe('CZ');

    $xml = (string) $response->json('data.content');
    expect($xml)->toContain('<Pisemnost')->toContain('<DPHDP3');

    [$valid, $errors] = czVrValidateXsd($xml, base_path('tests/Fixtures/vat-return/dphdp3_epo2.xsd'));
    expect($errors)->toBeEmpty();
    expect($valid)->toBeTrue();

    $veta1 = czVrAttrs($xml, 'Veta1');
    expect($veta1['obrat23'])->toBe('1000.00')
        ->and($veta1['dan23'])->toBe('210.00')
        ->and($veta1['obrat5'])->toBe('500.00')
        ->and($veta1['dan5'])->toBe('60.00')
        ->and($veta1['dan_pzb23'])->toBe('84.00');

    $veta4 = czVrAttrs($xml, 'Veta4');
    expect($veta4['odp_tuz23'])->toBe('42.00')
        ->and($veta4['od_zdp23'])->toBe('84.00');

    $veta6 = czVrAttrs($xml, 'Veta6');
    // dan_zocelk = 210 + 60 + 84
    expect($veta6['dan_zocelk'])->toBe('354.00')
        // odp_zocelk = 42 + 84
        ->and($veta6['odp_zocelk'])->toBe('126.00')
        // dano_da = 354 - 126
        ->and($veta6['dano_da'])->toBe('228.00')
        ->and($veta6['dano_no'] ?? null)->toBeNull();
});

it('rejects an annual-scope CZ VAT return — requires a month or quarter', function (): void {
    [$user] = vcsScope('CZ');

    $this->actingAs($user)->postJson('/api/v1/tax-filings', [
        'type' => 'vat_return', 'period_year' => 2027,
    ])->assertStatus(422);
});

it('builds a schema-valid golden CZ XML from a directly constructed report', function (): void {
    $user = User::factory()->make([
        'title' => null, 'name' => 'Petr', 'surname' => 'Svoboda',
        'email' => 'petr.svoboda@example.cz', 'phone' => null,
        'dic' => '12345678', 'country' => 'CZ',
        'address' => 'Hlavní 1', 'city' => 'Praha', 'postal_code' => '11000',
    ]);

    $report = new VatReturnReportData(
        country: 'CZ',
        year: 2027,
        month: 3,
        quarter: null,
        outputByRate: ['21' => ['base' => 1000.0, 'vat' => 210.0]],
        euAcquisitionBase: 0.0,
        euAcquisitionVat: 0.0,
        importBase: 0.0,
        importVat: 0.0,
        domesticInputBase: 0.0,
        domesticInputVat: 0.0,
        assumptions: [],
    );

    $xml = app(DphDp3XmlBuilder::class)->build($report, $user);

    [$valid, $errors] = czVrValidateXsd($xml, base_path('tests/Fixtures/vat-return/dphdp3_epo2.xsd'));
    expect($errors)->toBeEmpty();
    expect($valid)->toBeTrue();

    $veta6 = czVrAttrs($xml, 'Veta6');
    expect($veta6['dan_zocelk'])->toBe('210.00')
        ->and($veta6['dano_da'])->toBe('210.00');
});
