<?php

declare(strict_types=1);
use App\Modules\Shared\Enums\VatStatus;

it('updates vat_status via the profile endpoint and syncs the legacy boolean', function (): void {
    $user = createUser(['vat_status' => 'non_payer']);

    $this->actingAs($user)
        ->putJson('/api/v1/auth/profile', ['vat_status' => 'payer'])
        ->assertOk()
        ->assertJsonPath('vat_status', 'payer')
        ->assertJsonPath('is_vat_payer', true);

    expect($user->refresh())
        ->vat_status->toBe(VatStatus::Payer)
        ->is_vat_payer->toBeTrue();
});

it('derives vat_status from the legacy is_vat_payer flag when vat_status is absent', function (): void {
    $user = createUser(['vat_status' => 'non_payer']);

    $this->actingAs($user)
        ->putJson('/api/v1/auth/profile', ['is_vat_payer' => true])
        ->assertOk()
        ->assertJsonPath('vat_status', 'payer')
        ->assertJsonPath('is_vat_payer', true);
});

it('lets vat_status win when both vat_status and the legacy flag are sent', function (): void {
    $user = createUser(['vat_status' => 'non_payer']);

    $this->actingAs($user)
        ->putJson('/api/v1/auth/profile', ['vat_status' => 'identified', 'is_vat_payer' => true])
        ->assertOk()
        ->assertJsonPath('vat_status', 'identified')
        ->assertJsonPath('is_vat_payer', false);
});

it('rejects the legacy is_vat_payer flag for an identified-person account', function (): void {
    $user = createUser(['vat_status' => 'identified']);

    $this->actingAs($user)
        ->putJson('/api/v1/auth/profile', ['is_vat_payer' => true])
        ->assertUnprocessable()
        ->assertJsonPath('message', __('auth.legacy_vat_payer_conflicts_with_identified'));

    expect($user->refresh()->vat_status)->toBe(VatStatus::Identified);
});
