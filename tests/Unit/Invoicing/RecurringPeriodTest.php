<?php

declare(strict_types=1);

use App\Modules\Invoicing\Domain\Enums\RecurringPeriod;
use Carbon\CarbonImmutable;

it('advances monthly preserving the day of month', function (): void {
    $next = RecurringPeriod::Monthly->nextDate(CarbonImmutable::parse('2026-01-15'), 15, false);

    expect($next->toDateString())->toBe('2026-02-15');
});

it('caps day 28 correctly in February', function (): void {
    $next = RecurringPeriod::Monthly->nextDate(CarbonImmutable::parse('2026-01-28'), 28, false);

    expect($next->toDateString())->toBe('2026-02-28');
});

it('uses the dynamic last day of month when flagged', function (string $from, string $expected): void {
    $next = RecurringPeriod::Monthly->nextDate(CarbonImmutable::parse($from), 1, true);

    expect($next->toDateString())->toBe($expected);
})->with([
    '31-day month' => ['2026-02-28', '2026-03-31'],
    '30-day month' => ['2026-03-31', '2026-04-30'],
    'leap February' => ['2028-01-31', '2028-02-29'],
    'non-leap February' => ['2026-01-31', '2026-02-28'],
]);

it('does not drift after passing through a short month', function (): void {
    $period = RecurringPeriod::Monthly;
    $date = CarbonImmutable::parse('2026-01-28');

    $date = $period->nextDate($date, 28, false); // 2026-02-28
    $date = $period->nextDate($date, 28, false); // must stay 28th, not erode

    expect($date->toDateString())->toBe('2026-03-28');
});

it('advances quarterly, semiannually and yearly', function (RecurringPeriod $period, string $expected): void {
    $next = $period->nextDate(CarbonImmutable::parse('2026-01-15'), 15, false);

    expect($next->toDateString())->toBe($expected);
})->with([
    'quarterly' => [RecurringPeriod::Quarterly, '2026-04-15'],
    'semiannually' => [RecurringPeriod::Semiannually, '2026-07-15'],
    'yearly' => [RecurringPeriod::Yearly, '2027-01-15'],
]);

it('re-anchors from a capped occurrence back to the intended day', function (): void {
    // Yearly on day 28 starting from a last-day-of-Feb occurrence stays on the 28th.
    $next = RecurringPeriod::Yearly->nextDate(CarbonImmutable::parse('2028-02-29'), 28, false);

    expect($next->toDateString())->toBe('2029-02-28');
});

it('returns a start-of-day date for last day of month', function (): void {
    $next = RecurringPeriod::Monthly->nextDate(CarbonImmutable::parse('2026-05-31'), 1, true);

    expect($next->toDateString())->toBe('2026-06-30')
        ->and($next->format('H:i:s'))->toBe('00:00:00');
});
