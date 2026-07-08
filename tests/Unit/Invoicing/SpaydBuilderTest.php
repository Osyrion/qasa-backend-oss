<?php

declare(strict_types=1);

use App\Modules\Invoicing\Domain\Services\SpaydBuilder;
use App\Modules\Shared\Enums\Currency;
use Illuminate\Support\Carbon;

it('builds a full SPAYD payload', function (): void {
    $payload = new SpaydBuilder()->build(
        iban: 'CZ58 5500 0000 0012 6509 8001',
        bic: 'RZBCCZPP',
        amount: 480.5,
        currency: Currency::CZK,
        variableSymbol: '2026001',
        message: 'FA-2026-001',
        dueDate: Carbon::parse('2026-07-21'),
    );

    expect($payload)->toBe(
        'SPD*1.0*ACC:CZ5855000000001265098001+RZBCCZPP*AM:480.50*CC:CZK*X-VS:2026001*MSG:FA-2026-001*DT:20260721'
    );
});

it('omits optional fields when not provided', function (): void {
    $payload = new SpaydBuilder()->build(
        iban: 'CZ5855000000001265098001',
        bic: null,
        amount: 1000.0,
        currency: Currency::CZK,
    );

    expect($payload)->toBe('SPD*1.0*ACC:CZ5855000000001265098001*AM:1000.00*CC:CZK');
});

it('strips diacritics and asterisks from the message', function (): void {
    $payload = new SpaydBuilder()->build(
        iban: 'CZ5855000000001265098001',
        bic: null,
        amount: 1.0,
        currency: Currency::CZK,
        message: 'Platba za *žluťoučké* zboží',
    );

    expect($payload)->toContain('MSG:Platba za zlutoucke zbozi')
        ->and(substr_count($payload, '*'))->toBe(5); // separators only
});

it('keeps only digits in the variable symbol', function (): void {
    $payload = new SpaydBuilder()->build(
        iban: 'CZ5855000000001265098001',
        bic: null,
        amount: 1.0,
        currency: Currency::CZK,
        variableSymbol: 'VS-2026001',
    );

    expect($payload)->toContain('X-VS:2026001');
});
