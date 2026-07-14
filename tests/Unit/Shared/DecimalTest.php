<?php

declare(strict_types=1);

use App\Modules\Shared\Support\Decimal;

it('rounds half away from zero, unlike bcmath\'s native truncating scale', function (): void {
    expect(Decimal::mul('33.333', '3'))->toBe('100.00')
        ->and(Decimal::round('2.005'))->toBe('2.01')
        ->and(Decimal::round('-2.005'))->toBe('-2.01')
        ->and(Decimal::round('100.995'))->toBe('101.00')
        ->and(Decimal::round('-0.001'))->toBe('0.00');
});

it('adds, subtracts, and divides with exact decimal precision', function (): void {
    expect(Decimal::add('10.10', '20.20'))->toBe('30.30')
        ->and(Decimal::sub('10.00', '3.335'))->toBe('6.67')
        ->and(Decimal::div('10', '3'))->toBe('3.33');
});

it('handles a 33.33% discount across three VAT rates without drift', function (): void {
    // Three item buckets at 20%, 10%, 0% VAT, base 100.00 each, then a
    // 33.33% document-level discount applied proportionally per bucket —
    // the exact scenario the VAT recap calculator computes per rate.
    $rates = ['20' => '100.00', '10' => '100.00', '0' => '100.00'];
    $discountPercent = '33.33';

    $factor = Decimal::sub('1', Decimal::div($discountPercent, '100', 10), 10);

    $totalVat = '0.00';
    $totalBase = '0.00';

    foreach ($rates as $rate => $base) {
        $discountedBase = Decimal::round(Decimal::mul($base, $factor, 10));
        $vat = Decimal::mul($discountedBase, Decimal::div($rate, '100', 10));

        $totalBase = Decimal::add($totalBase, $discountedBase);
        $totalVat = Decimal::add($totalVat, $vat);
    }

    // 100 * 0.6667 = 66.67 per bucket, three buckets => 200.01 base.
    expect($totalBase)->toBe('200.01')
        // VAT: 66.67*0.20 + 66.67*0.10 + 66.67*0 = 13.334 + 6.667 = 20.00 (rounded)
        ->and($totalVat)->toBe('20.00');
});

it('handles halier (cent) remainders in repeated addition', function (): void {
    $sum = '0.00';
    for ($i = 0; $i < 3; $i++) {
        $sum = Decimal::add($sum, '0.10');
    }

    expect($sum)->toBe('0.30');
});
