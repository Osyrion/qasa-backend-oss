<?php

declare(strict_types=1);

use App\Modules\Invoicing\Domain\Services\EpcQrBuilder;

it('builds an EPC069-12 payload', function (): void {
    $payload = new EpcQrBuilder()->build(
        iban: 'SK31 1200 0000 1987 4263 7541',
        bic: 'GIBASKBX',
        beneficiaryName: 'Ján Novák',
        amount: 1234.56,
        remittanceText: 'FA-2026-001 VS 2026001',
    );

    expect(explode("\n", $payload))->toBe([
        'BCD',
        '002',
        '1',
        'SCT',
        'GIBASKBX',
        'Ján Novák',
        'SK3112000000198742637541',
        'EUR1234.56',
        '',
        '',
        'FA-2026-001 VS 2026001',
    ]);
});

it('leaves the BIC line empty when missing (allowed in v002)', function (): void {
    $payload = new EpcQrBuilder()->build(
        iban: 'SK3112000000198742637541',
        bic: null,
        beneficiaryName: 'Ján Novák',
        amount: 10.0,
    );

    $lines = explode("\n", $payload);

    expect($lines[4])->toBe('')
        ->and($lines[7])->toBe('EUR10.00');
});
