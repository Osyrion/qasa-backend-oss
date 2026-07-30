<?php

declare(strict_types=1);

use App\Modules\Invoicing\Domain\Banking\PayBySquareBuilder;
use App\Modules\Shared\Enums\Currency;
use Illuminate\Support\Carbon;

/*
|--------------------------------------------------------------------------
| ⚠️ Not spec-verified — see the class docblock. This is a self-consistency
| golden test (regression guard against an accidental behavior change),
| not proof the payload matches the official bysquare specification.
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

    expect($payload)->toBe('BK000004VVVVVVVVVVVVU00GNR04HK071EPCOQIDDAOCN73FUJ00H8792CFJM7NE3TM0922DLD0GFOSL5B44JNFACCPCFTRU74HJ4BDNCI8V8BKCNSSCRSRF9QGGQ7B5F52OA9DAI13LCB1H7C48BMP3N48R79U7OMB0BVVJC8N00')
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
