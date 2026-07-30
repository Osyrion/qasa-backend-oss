<?php

declare(strict_types=1);

use App\Modules\Shared\Support\Decimal;
use Brick\Math\BigDecimal;

it('adds without float drift', function (): void {
    expect((string) Decimal::of('0.1')->plus(Decimal::of('0.2')))->toBe('0.3');

    // The float answer to the same sum is 0.30000000000000004.
    expect(0.1 + 0.2)->not->toBe(0.3);
});

it('keeps a discount factor exact', function (): void {
    // 1 - 15/100 is 0.8499999999999999 as a float, so a large base drifts
    // below the half-cent boundary and rounds down.
    $factor = Decimal::of(1)->minus(Decimal::percentOf(1, '15'));

    expect(Decimal::money(Decimal::of('1234.50')->multipliedBy($factor)))->toBe('1049.33');
});

it('rounds half away from zero at the money boundary', function (string $input, string $expected): void {
    expect(Decimal::money($input))->toBe($expected);
})->with([
    ['1.005', '1.01'],
    ['1.004', '1.00'],
    ['2.675', '2.68'],
    ['-1.005', '-1.01'],
    ['0', '0.00'],
]);

it('computes a percentage at full precision', function (): void {
    // 21 % of 33.33 is 6.9993 — quantising the intermediate would lose it.
    expect((string) Decimal::percentOf('33.33', '21'))->toStartWith('6.9993');
    expect(Decimal::money(Decimal::percentOf('33.33', '21')))->toBe('7.00');
});

it('sums a list exactly', function (): void {
    $values = array_fill(0, 10, '0.1');

    expect((string) Decimal::sum($values))->toBe('1.0')
        ->and(Decimal::money(Decimal::sum($values)))->toBe('1.00');
});

it('accepts the string|int|float mix Eloquent casts produce', function (): void {
    expect(Decimal::money('12.5'))->toBe('12.50')
        ->and(Decimal::money(12))->toBe('12.00')
        ->and(Decimal::money(12.5))->toBe('12.50')
        ->and(Decimal::money(null))->toBe('0.00')
        ->and(Decimal::money(BigDecimal::of('12.505')))->toBe('12.51');
});

it('does not inherit a float literal\'s own error', function (): void {
    // (string) 0.1 + 0.2 would carry 0.30000000000000004 into the decimal.
    expect(Decimal::money(0.1 + 0.2))->toBe('0.30');
});

it('survives a long chain of line totals without losing a cent', function (): void {
    // 1000 lines of 0.07 is exactly 70.00; accumulating it in a float and
    // rounding at the end lands on 70.00 too, but the intermediate sum is
    // already 69.99999999999859 — the margin that a discount factor or a
    // currency conversion then pushes over the edge.
    $sum = Decimal::sum(array_fill(0, 1000, '0.07'));

    expect(Decimal::money($sum))->toBe('70.00')
        ->and((string) $sum)->toBe('70.00');
});
