<?php

declare(strict_types=1);

use App\Modules\Auth\Domain\Models\User;
use App\Modules\Clients\Domain\Models\Client;
use App\Modules\Invoicing\Application\Services\VatRateSeederService;
use App\Modules\Invoicing\Domain\Models\SupplierInvoice;

/**
 * @return array{0: User, 1: Client}
 */
function selfAssessmentScope(string $vatStatus): array
{
    $user = createUser(['country' => 'SK', 'vat_status' => $vatStatus]);
    app(VatRateSeederService::class)->seedFor($user);
    $vendor = Client::factory()->vendor()->create(['user_id' => $user->id, 'country' => 'DE']);

    return [$user, $vendor];
}

it('rejects self-assessed VAT for a non-payer', function (): void {
    [$user, $vendor] = selfAssessmentScope('non_payer');

    $this->actingAs($user)->postJson('/api/v1/supplier-invoices', [
        'client_id' => $vendor->id,
        'supplier_invoice_number' => 'INV-1',
        'issued_at' => now()->toDateString(),
        'currency' => 'EUR',
        'vat_regime' => 'eu_reverse_charge',
        'vat_lines' => [
            ['vat_rate' => 23, 'base' => 1000, 'vat_amount' => 230],
        ],
    ])->assertStatus(422);
});

it('creates a self-assessed eu_reverse_charge supplier invoice with the vendor total net of VAT', function (): void {
    [$user, $vendor] = selfAssessmentScope('payer');

    $response = $this->actingAs($user)->postJson('/api/v1/supplier-invoices', [
        'client_id' => $vendor->id,
        'supplier_invoice_number' => 'INV-1',
        'issued_at' => now()->toDateString(),
        'currency' => 'EUR',
        'vat_regime' => 'eu_reverse_charge',
        'vat_lines' => [
            ['vat_rate' => 23, 'base' => 1000, 'vat_amount' => 230],
        ],
    ]);

    $response->assertCreated()
        ->assertJsonPath('vat_regime', 'eu_reverse_charge')
        ->assertJsonPath('subtotal', 1000)
        ->assertJsonPath('vat_amount', 230)
        ->assertJsonPath('self_assessed_vat_amount', 230)
        ->assertJsonPath('total', 1000);
});

it('allows an identified person to self-assess too, without a right of deduction being modeled', function (): void {
    [$user, $vendor] = selfAssessmentScope('identified');

    $this->actingAs($user)->postJson('/api/v1/supplier-invoices', [
        'client_id' => $vendor->id,
        'supplier_invoice_number' => 'INV-1',
        'issued_at' => now()->toDateString(),
        'currency' => 'EUR',
        'vat_regime' => 'eu_reverse_charge',
        'vat_lines' => [
            ['vat_rate' => 23, 'base' => 1000, 'vat_amount' => 230],
        ],
    ])->assertCreated()->assertJsonPath('total', 1000);
});

it('rejects an eu_reverse_charge rate outside the catalog but allows any rate for import', function (): void {
    [$user, $vendor] = selfAssessmentScope('payer');

    $this->actingAs($user)->postJson('/api/v1/supplier-invoices', [
        'client_id' => $vendor->id,
        'supplier_invoice_number' => 'INV-1',
        'issued_at' => now()->toDateString(),
        'currency' => 'EUR',
        'vat_regime' => 'eu_reverse_charge',
        'vat_lines' => [
            ['vat_rate' => 17.5, 'base' => 1000, 'vat_amount' => 175],
        ],
    ])->assertUnprocessable();

    $this->actingAs($user)->postJson('/api/v1/supplier-invoices', [
        'client_id' => $vendor->id,
        'supplier_invoice_number' => 'INV-2',
        'issued_at' => now()->toDateString(),
        'currency' => 'EUR',
        'vat_regime' => 'import',
        'vat_lines' => [
            ['vat_rate' => 17.5, 'base' => 1000, 'vat_amount' => 175],
        ],
    ])->assertCreated();
});

it('defaults new supplier invoices to the domestic regime unaffected by self-assessment', function (): void {
    [$user, $vendor] = selfAssessmentScope('payer');

    $response = $this->actingAs($user)->postJson('/api/v1/supplier-invoices', [
        'client_id' => $vendor->id,
        'supplier_invoice_number' => 'INV-1',
        'issued_at' => now()->toDateString(),
        'currency' => 'EUR',
        'vat_lines' => [
            ['vat_rate' => 23, 'base' => 1000, 'vat_amount' => 230],
        ],
    ]);

    $response->assertCreated()
        ->assertJsonPath('vat_regime', 'domestic')
        ->assertJsonPath('self_assessed_vat_amount', 0)
        ->assertJsonPath('total', 1230);

    $invoice = SupplierInvoice::withoutGlobalScope('user')->whereKey($response->json('id'))->firstOrFail();
    expect($invoice->vat_regime->value)->toBe('domestic');
});
