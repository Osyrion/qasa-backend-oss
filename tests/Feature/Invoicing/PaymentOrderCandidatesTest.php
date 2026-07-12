<?php

declare(strict_types=1);

use App\Modules\Auth\Domain\Models\User;
use App\Modules\Clients\Domain\Models\Client;
use App\Modules\Invoicing\Domain\Models\BankAccount;
use App\Modules\Invoicing\Domain\Models\SupplierInvoice;
use App\Modules\Shared\Enums\Currency;

/**
 * @param  array<string, mixed>  $overrides
 */
function candidateInvoice(User $user, array $overrides = []): SupplierInvoice
{
    $vendor = Client::factory()->vendor()->create(['user_id' => $user->id]);

    return SupplierInvoice::factory()->received()->create(array_merge([
        'user_id' => $user->id,
        'client_id' => $vendor->id,
        'currency' => Currency::CZK->value,
        'vendor_account_number' => '123456789',
        'vendor_bank_code' => '0800',
        'total' => 121,
    ], $overrides));
}

it('splits candidates into abo eligible and other groups', function (): void {
    $user = createUser();
    $domesticCzk = candidateInvoice($user);
    $ibanOnlyCzk = candidateInvoice($user, [
        'vendor_account_number' => null,
        'vendor_bank_code' => null,
        'vendor_iban' => 'CZ6508000000192000145399',
    ]);
    $eur = candidateInvoice($user, ['currency' => Currency::EUR->value]);
    candidateInvoice($user, ['status' => 'paid']); // not a candidate at all

    $response = $this->actingAs($user)->getJson('/api/v1/payment-orders/candidates');

    $response->assertOk();

    $aboIds = array_column($response->json('abo_eligible'), 'id');
    $otherIds = array_column($response->json('other'), 'id');

    expect($aboIds)->toBe([$domesticCzk->id])
        ->and($otherIds)->toContain($ibanOnlyCzk->id, $eur->id)
        ->and($otherIds)->toHaveCount(2);
});

it('flags rows without an account and rows in another currency as not selectable', function (): void {
    $user = createUser();
    $account = BankAccount::factory()->currency(Currency::CZK)->create(['user_id' => $user->id]);
    $selectable = candidateInvoice($user);
    $noAccount = candidateInvoice($user, ['vendor_account_number' => null, 'vendor_bank_code' => null]);
    $eur = candidateInvoice($user, ['currency' => Currency::EUR->value]);

    $response = $this->actingAs($user)->getJson('/api/v1/payment-orders/candidates?bank_account_id='.$account->id);

    $response->assertOk();

    $rows = collect([...$response->json('abo_eligible'), ...$response->json('other')])->keyBy('id');

    expect($rows[$selectable->id]['selectable'])->toBeTrue()
        ->and($rows[$selectable->id]['selectable_reason'])->toBeNull()
        ->and($rows[$noAccount->id]['selectable'])->toBeFalse()
        ->and($rows[$noAccount->id]['selectable_reason'])->not->toBeNull()
        ->and($rows[$eur->id]['selectable'])->toBeFalse()
        ->and($rows[$eur->id]['selectable_reason'])->not->toBeNull();
});

it('hides invoices already handed to payment when hide_handed is set', function (): void {
    $user = createUser();
    $handed = candidateInvoice($user, ['handed_to_payment_at' => now()]);
    $fresh = candidateInvoice($user);

    $all = $this->actingAs($user)->getJson('/api/v1/payment-orders/candidates');
    $filtered = $this->actingAs($user)->getJson('/api/v1/payment-orders/candidates?hide_handed=1');

    $allIds = array_column($all->json('abo_eligible'), 'id');
    $filteredIds = array_column($filtered->json('abo_eligible'), 'id');

    expect($allIds)->toContain($handed->id, $fresh->id)
        ->and($filteredIds)->toBe([$fresh->id]);
});

it('never exposes another account\'s invoices or bank accounts', function (): void {
    $user = createUser();
    $stranger = createUser();
    $foreignInvoice = candidateInvoice($stranger);
    $foreignAccount = BankAccount::factory()->create(['user_id' => $stranger->id]);

    $response = $this->actingAs($user)->getJson('/api/v1/payment-orders/candidates');

    $ids = array_column([...$response->json('abo_eligible'), ...$response->json('other')], 'id');
    expect($ids)->not->toContain($foreignInvoice->id);

    $this->actingAs($user)
        ->getJson('/api/v1/payment-orders/candidates?bank_account_id='.$foreignAccount->id)
        ->assertNotFound();
});
