<?php

declare(strict_types=1);

use App\Modules\Shared\Exceptions\DomainException;
use App\Modules\Taxation\Domain\Models\TaxFiling;

it('generates a control statement filing snapshot', function (): void {
    $user = createUser(['country' => 'SK']);

    $response = $this->actingAs($user)->postJson('/api/v1/tax-filings', [
        'type' => 'control_statement',
        'period_year' => 2026,
        'period_month' => 7,
    ]);

    $response->assertCreated();
    expect($response->json('data.type'))->toBe('control_statement')
        ->and($response->json('data.country'))->toBe('SK')
        ->and($response->json('data.status'))->toBe('generated')
        ->and($response->json('data.period_year'))->toBe(2026)
        ->and($response->json('data.period_month'))->toBe(7)
        ->and($response->json('data.supersedes_id'))->toBeNull()
        ->and($response->json('data.content'))->toContain('<?xml');

    $filing = TaxFiling::query()->findOrFail($response->json('data.id'));
    expect($filing->sha256)->toBe(hash('sha256', $filing->content));
});

it('rejects a control statement without a month or quarter', function (): void {
    $user = createUser(['country' => 'SK']);

    $this->actingAs($user)->postJson('/api/v1/tax-filings', [
        'type' => 'control_statement',
        'period_year' => 2026,
    ])->assertUnprocessable();
});

it('generates an EU sales list filing for a whole year with no month or quarter', function (): void {
    $user = createUser(['country' => 'SK']);

    $response = $this->actingAs($user)->postJson('/api/v1/tax-filings', [
        'type' => 'eu_sales_list',
        'period_year' => 2026,
    ]);

    $response->assertCreated();
    expect($response->json('data.type'))->toBe('eu_sales_list')
        ->and(json_decode((string) $response->json('data.content'), true))->toBe([]);
});

it('supersedes the previous filing when generating again for the same period', function (): void {
    $user = createUser(['country' => 'SK']);

    $first = $this->actingAs($user)->postJson('/api/v1/tax-filings', [
        'type' => 'control_statement', 'period_year' => 2026, 'period_month' => 7,
    ])->json('data.id');

    $second = $this->actingAs($user)->postJson('/api/v1/tax-filings', [
        'type' => 'control_statement', 'period_year' => 2026, 'period_month' => 7,
    ]);

    $second->assertCreated();
    expect($second->json('data.supersedes_id'))->toBe($first);

    $firstFiling = TaxFiling::query()->findOrFail($first);
    expect($firstFiling->status->value)->toBe('superseded');
});

it('does not supersede a filing for a different period', function (): void {
    $user = createUser(['country' => 'SK']);

    $july = $this->actingAs($user)->postJson('/api/v1/tax-filings', [
        'type' => 'control_statement', 'period_year' => 2026, 'period_month' => 7,
    ])->json('data.id');

    $this->actingAs($user)->postJson('/api/v1/tax-filings', [
        'type' => 'control_statement', 'period_year' => 2026, 'period_month' => 8,
    ])->assertCreated();

    expect(TaxFiling::query()->findOrFail($july)->status->value)->toBe('generated');
});

it('rejects generating an income_tax filing — not wired to a generator', function (): void {
    // vat_return IS generatable for both SK and CZ — see
    // VatReturnXmlTest.php / CzVatReturnXmlTest.php.
    $user = createUser(['country' => 'SK']);

    $this->actingAs($user)->postJson('/api/v1/tax-filings', [
        'type' => 'income_tax', 'period_year' => 2026,
    ])->assertUnprocessable();
});

it('lists filings filtered by type, status and year', function (): void {
    $user = createUser(['country' => 'SK']);

    $this->actingAs($user)->postJson('/api/v1/tax-filings', [
        'type' => 'control_statement', 'period_year' => 2025, 'period_month' => 12,
    ])->assertCreated();
    $this->actingAs($user)->postJson('/api/v1/tax-filings', [
        'type' => 'eu_sales_list', 'period_year' => 2026,
    ])->assertCreated();

    $response = $this->actingAs($user)->getJson('/api/v1/tax-filings?type=eu_sales_list');
    $response->assertOk();
    expect($response->json('data'))->toHaveCount(1)
        ->and($response->json('data.0.type'))->toBe('eu_sales_list');

    $byYear = $this->actingAs($user)->getJson('/api/v1/tax-filings?year=2025');
    expect($byYear->json('data'))->toHaveCount(1);
});

it('marks a generated filing as filed', function (): void {
    $user = createUser(['country' => 'SK']);
    $id = $this->actingAs($user)->postJson('/api/v1/tax-filings', [
        'type' => 'control_statement', 'period_year' => 2026, 'period_month' => 7,
    ])->json('data.id');

    $response = $this->actingAs($user)->postJson("/api/v1/tax-filings/{$id}/mark-filed", [
        'notes' => 'Podané cez eDane portál',
    ]);

    $response->assertOk();
    expect($response->json('data.status'))->toBe('filed')
        ->and($response->json('data.filed_at'))->not->toBeNull()
        ->and($response->json('data.notes'))->toBe('Podané cez eDane portál');
});

it('rejects marking an already-filed filing as filed again', function (): void {
    $user = createUser(['country' => 'SK']);
    $id = $this->actingAs($user)->postJson('/api/v1/tax-filings', [
        'type' => 'control_statement', 'period_year' => 2026, 'period_month' => 7,
    ])->json('data.id');
    $this->actingAs($user)->postJson("/api/v1/tax-filings/{$id}/mark-filed")->assertOk();

    $this->actingAs($user)->postJson("/api/v1/tax-filings/{$id}/mark-filed")->assertUnprocessable();
});

it('rejects marking a superseded filing as filed', function (): void {
    $user = createUser(['country' => 'SK']);
    $first = $this->actingAs($user)->postJson('/api/v1/tax-filings', [
        'type' => 'control_statement', 'period_year' => 2026, 'period_month' => 7,
    ])->json('data.id');
    $this->actingAs($user)->postJson('/api/v1/tax-filings', [
        'type' => 'control_statement', 'period_year' => 2026, 'period_month' => 7,
    ])->assertCreated();

    $this->actingAs($user)->postJson("/api/v1/tax-filings/{$first}/mark-filed")->assertUnprocessable();
});

it('does not let a user access another account\'s filings', function (): void {
    $owner = createUser(['country' => 'SK']);
    $id = $this->actingAs($owner)->postJson('/api/v1/tax-filings', [
        'type' => 'control_statement', 'period_year' => 2026, 'period_month' => 7,
    ])->json('data.id');

    $stranger = createUser(['country' => 'SK']);

    $this->actingAs($stranger)->getJson("/api/v1/tax-filings/{$id}")->assertNotFound();
    $this->actingAs($stranger)->postJson("/api/v1/tax-filings/{$id}/mark-filed")->assertNotFound();

    $list = $this->actingAs($stranger)->getJson('/api/v1/tax-filings');
    expect($list->json('data'))->toBe([]);
});

it('requires completed tax residency', function (): void {
    $user = createUser(['country' => null]);

    $this->actingAs($user)->postJson('/api/v1/tax-filings', [
        'type' => 'control_statement', 'period_year' => 2026, 'period_month' => 7,
    ])->assertStatus(409);
});

it('requires authentication', function (): void {
    $this->getJson('/api/v1/tax-filings')->assertUnauthorized();
});

it('never allows editing a generated snapshot\'s content at the model level', function (): void {
    $user = createUser(['country' => 'SK']);
    $filing = TaxFiling::factory()->create(['user_id' => $user->id]);

    expect(fn () => $filing->update(['content' => 'tampered']))
        ->toThrow(DomainException::class);
});

it('still allows the mutable fields (status, filed_at, notes) to change at the model level', function (): void {
    $user = createUser(['country' => 'SK']);
    $filing = TaxFiling::factory()->create(['user_id' => $user->id]);

    $filing->update(['status' => 'filed', 'filed_at' => now(), 'notes' => 'ok']);

    /** @var TaxFiling $fresh */
    $fresh = $filing->fresh();
    expect($fresh->status->value)->toBe('filed');
});
