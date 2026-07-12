<?php

declare(strict_types=1);

use App\Modules\Calendar\Application\Services\EventTimeNormalizer;
use Carbon\CarbonImmutable;

beforeEach(function (): void {
    config(['calendar.slot_minutes' => 15]);
    $this->normalizer = new EventTimeNormalizer;
});

it('floors the start and ceils the end to the slot grid', function (): void {
    [$start, $end] = $this->normalizer->snapToGrid(
        CarbonImmutable::parse('2026-08-03 10:07'),
        CarbonImmutable::parse('2026-08-03 10:52'),
    );

    expect($start->format('H:i'))->toBe('10:00')
        ->and($end->format('H:i'))->toBe('11:00');
});

it('leaves already-aligned times unchanged', function (): void {
    [$start, $end] = $this->normalizer->snapToGrid(
        CarbonImmutable::parse('2026-08-03 10:00'),
        CarbonImmutable::parse('2026-08-03 10:30'),
    );

    expect($start->format('H:i'))->toBe('10:00')
        ->and($end->format('H:i'))->toBe('10:30');
});

it('guarantees at least one slot when snapping collapses the interval', function (): void {
    [$start, $end] = $this->normalizer->snapToGrid(
        CarbonImmutable::parse('2026-08-03 10:05'),
        CarbonImmutable::parse('2026-08-03 10:07'),
    );

    expect($start->format('H:i'))->toBe('10:00')
        ->and($end->format('H:i'))->toBe('10:15');
});

it('normalizes an all-day event to midnight-to-midnight', function (): void {
    [$start, $end] = $this->normalizer->normalizeAllDay(CarbonImmutable::parse('2026-08-03 15:37'));

    expect($start->format('Y-m-d H:i'))->toBe('2026-08-03 00:00')
        ->and($end->format('Y-m-d H:i'))->toBe('2026-08-04 00:00');
});

it('allows an event ending exactly at midnight of the next day', function (): void {
    $this->normalizer->assertSameDay(
        CarbonImmutable::parse('2026-08-03 22:00'),
        CarbonImmutable::parse('2026-08-04 00:00'),
    );
})->throwsNoExceptions();

it('rejects an event crossing into the following day past midnight', function (): void {
    $this->normalizer->assertSameDay(
        CarbonImmutable::parse('2026-08-03 22:00'),
        CarbonImmutable::parse('2026-08-04 01:00'),
    );
})->throws(RuntimeException::class);
