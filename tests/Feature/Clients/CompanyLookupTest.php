<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    Cache::flush();
});

/**
 * @return array<string, mixed>
 */
function aresBody(): array
{
    return [
        'ico' => '27074358',
        'obchodniJmeno' => 'ACME s.r.o.',
        'dic' => 'CZ27074358',
        'sidlo' => [
            'kodStatu' => 'CZ',
            'nazevObce' => 'Praha',
            'nazevUlice' => 'Testovací',
            'cisloDomovni' => '1',
            'cisloOrientacni' => '2',
            'psc' => '11000',
            'textovaAdresa' => 'Testovací 1/2, 110 00 Praha',
        ],
    ];
}

/**
 * @return array<string, mixed>
 */
function rpoBody(): array
{
    return [
        'results' => [[
            'identifiers' => [['value' => '35697270']],
            'fullNames' => [
                ['value' => 'Pôvodný názov', 'validTo' => '2019-01-01'],
                ['value' => 'SK Firma a.s.', 'validTo' => null],
            ],
            'addresses' => [[
                'street' => 'Hlavná',
                'buildingNumber' => '5',
                'postalCodes' => ['81101'],
                'municipality' => ['value' => 'Bratislava'],
                'country' => ['code' => 'SK'],
                'validTo' => null,
            ]],
        ]],
    ];
}

it('fetches Czech company data from ARES by IČO', function (): void {
    Http::fake(['ares.gov.cz/*' => Http::response(aresBody())]);

    $this->actingAs(createUser())
        ->getJson('/api/v1/clients/lookup?country=CZ&ico=27074358')
        ->assertOk()
        ->assertJson([
            'company_name' => 'ACME s.r.o.',
            'ico' => '27074358',
            'dic' => 'CZ27074358',
            'vat_id' => 'CZ27074358',
            'address' => 'Testovací 1/2',
            'city' => 'Praha',
            'postal_code' => '110 00',
            'country' => 'CZ',
        ]);
});

it('fetches Slovak company data from RPO by IČO', function (): void {
    Http::fake(['api.statistics.sk/*' => Http::response(rpoBody())]);

    $this->actingAs(createUser())
        ->getJson('/api/v1/clients/lookup?country=SK&ico=35697270')
        ->assertOk()
        ->assertJson([
            'company_name' => 'SK Firma a.s.',
            'ico' => '35697270',
            'dic' => null,
            'vat_id' => null,
            'address' => 'Hlavná 5',
            'city' => 'Bratislava',
            'postal_code' => '811 01',
            'country' => 'SK',
        ]);
});

it('returns 422 when the company is not found', function (): void {
    Http::fake(['ares.gov.cz/*' => Http::response('', 404)]);

    $this->actingAs(createUser())
        ->getJson('/api/v1/clients/lookup?country=CZ&ico=00000000')
        ->assertStatus(422);
});

it('rejects an unsupported lookup country via validation', function (): void {
    $this->actingAs(createUser())
        ->getJson('/api/v1/clients/lookup?country=DE&ico=27074358')
        ->assertStatus(422)
        ->assertJsonValidationErrorFor('country');
});

it('confirms a valid VAT number through VIES', function (): void {
    Http::fake(['ec.europa.eu/*' => Http::response([
        'isValid' => true,
        'name' => 'SK Firma a.s.',
        'address' => 'Hlavná 5, Bratislava',
    ])]);

    $this->actingAs(createUser())
        ->getJson('/api/v1/clients/verify-vat?country=SK&vat_id=SK2020270621')
        ->assertOk()
        ->assertJson([
            'valid' => true,
            'country' => 'SK',
            'vat_number' => 'SK2020270621',
            'name' => 'SK Firma a.s.',
        ]);
});

it('reports an invalid VAT number through VIES', function (): void {
    Http::fake(['ec.europa.eu/*' => Http::response([
        'isValid' => false,
        'name' => '---',
        'address' => '---',
    ])]);

    $this->actingAs(createUser())
        ->getJson('/api/v1/clients/verify-vat?country=SK&vat_id=SK9999999999')
        ->assertOk()
        ->assertJson(['valid' => false, 'name' => null, 'address' => null]);
});

it('returns 422 when VIES is unavailable', function (): void {
    Http::fake(['ec.europa.eu/*' => Http::response('', 500)]);

    $this->actingAs(createUser())
        ->getJson('/api/v1/clients/verify-vat?country=SK&vat_id=SK2020270621')
        ->assertStatus(422);
});

it('requires authentication', function (): void {
    $this->getJson('/api/v1/clients/lookup?country=CZ&ico=27074358')->assertUnauthorized();
    $this->getJson('/api/v1/clients/verify-vat?country=SK&vat_id=SK2020270621')->assertUnauthorized();
});

it('persists vat_id when creating a client', function (): void {
    $this->actingAs(createUser())
        ->postJson('/api/v1/clients', [
            'client_type' => 'company',
            'company_name' => 'ACME s.r.o.',
            'ico' => '27074358',
            'dic' => 'CZ27074358',
            'vat_id' => 'CZ27074358',
            'is_vat_payer' => true,
            'country' => 'CZ',
            'currency' => 'CZK',
            'locale' => 'cs',
        ])
        ->assertCreated()
        ->assertJsonPath('vat_id', 'CZ27074358');
});
