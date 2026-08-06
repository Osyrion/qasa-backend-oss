<?php

declare(strict_types=1);

use App\Modules\Invoicing\Domain\Banking\PayBySquareBuilder;
use App\Modules\Shared\Enums\Currency;
use Illuminate\Support\Carbon;

/*
|--------------------------------------------------------------------------
| Regression guard against accidental behavior changes (determinism, amount
| sensitivity). Spec conformance against an independent reference is covered
| separately, with byte-exact golden vectors, by PayBySquareGoldenTest.
|--------------------------------------------------------------------------
*/

it('builds a deterministic Pay by Square payload', function (): void {
    $payload = new PayBySquareBuilder()->build(
        iban: 'SK3112000000198742637541',
        bic: 'GIBASKBX',
        amount: 100.50,
        currency: Currency::EUR,
        variableSymbol: '2026001',
        dueDate: Carbon::parse('2026-07-21'),
        beneficiaryName: 'Ján Novák',
        paymentNote: 'FA-2026-001',
    );

    expect($payload)->toBe('0806C0001ML0USEO93146TIU1R6LORO13O29PQCCON6UDUVO2JEDFOMTJQOR1KOKGTMTJGN8T9GR1THSK36PH0EUFA5MTQ391HOBKCH3NQMHKQCNP34ODU0VGS9EBE0RV7L07O1LS0EKS2IU9BD17VTO05000')
        ->and($payload)->toMatch('/^[0-9A-V]+$/');
});

it('is deterministic across builds with the same input', function (): void {
    $build = fn (): string => new PayBySquareBuilder()->build(
        iban: 'SK3112000000198742637541',
        bic: null,
        amount: 10.0,
        currency: Currency::EUR,
        variableSymbol: null,
        dueDate: null,
        beneficiaryName: 'Test',
    );

    expect($build())->toBe($build());
});

it('changes the payload when the amount changes', function (): void {
    $build = fn (float $amount): string => new PayBySquareBuilder()->build(
        iban: 'SK3112000000198742637541',
        bic: null,
        amount: $amount,
        currency: Currency::EUR,
        variableSymbol: null,
        dueDate: null,
        beneficiaryName: 'Test',
    );

    expect($build(10.0))->not->toBe($build(20.0));
});
