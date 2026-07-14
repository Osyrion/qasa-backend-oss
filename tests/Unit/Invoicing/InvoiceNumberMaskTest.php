<?php

declare(strict_types=1);

use App\Modules\Invoicing\Domain\Services\InvoiceNumberMask;
use Carbon\CarbonImmutable;

it('formats a sequence using date and width placeholders', function (string $mask, int $sequence, string $date, string $expected): void {
    $formatted = (new InvoiceNumberMask($mask))->format($sequence, CarbonImmutable::parse($date));

    expect($formatted)->toBe($expected);
})->with([
    'year + 4-digit sequence, seq 1' => ['{YYYY}{NNNN}', 1, '2026-06-01', '20260001'],
    'year + 4-digit sequence, seq 42' => ['{YYYY}{NNNN}', 42, '2026-06-01', '20260042'],
    'short year + literal + 3-digit sequence' => ['{YY}01{NNN}', 1, '2026-06-01', '2601001'],
    'legacy format' => ['FA-{YYYY}-{NNN}', 1, '2026-06-01', 'FA-2026-001'],
    'month + day + 2-digit sequence' => ['{MM}{DD}-{NN}', 5, '2026-07-09', '0709-05'],
]);

it('round-trips the sequence back out of a formatted number', function (): void {
    $mask = new InvoiceNumberMask('{YYYY}{NNNN}');
    $date = CarbonImmutable::parse('2026-06-01');

    $number = $mask->format(42, $date);

    expect($mask->extractSequence($number, $date))->toBe(42);
});

it('does not match a number from a different period', function (): void {
    $mask = new InvoiceNumberMask('{YYYY}{NNNN}');

    $number = $mask->format(1, CarbonImmutable::parse('2025-01-01'));

    expect($mask->extractSequence($number, CarbonImmutable::parse('2026-01-01')))->toBeNull();
});

it('does not match a number from a different type prefix', function (): void {
    $mask = new InvoiceNumberMask('PF-{YYYY}{NNNN}');
    $date = CarbonImmutable::parse('2026-06-01');

    expect($mask->extractSequence('DB-20260001', $date))->toBeNull();
});

it('accepts a valid mask', function (string $mask): void {
    expect(InvoiceNumberMask::isValid($mask))->toBeTrue();
})->with([
    '{YYYY}{NNNN}',
    '{YY}01{NNN}',
    'FA-{YYYY}-{NNN}',
    '{NNNNN}',
    'PF-{YYYY}{NNNN}',
]);

it('rejects an invalid mask', function (string $mask): void {
    expect(InvoiceNumberMask::isValid($mask))->toBeFalse();
})->with([
    'no sequence token' => ['{YYYY}'],
    'two sequence tokens' => ['{NNN}-{NN}'],
    'unknown placeholder' => ['{XX}{NNN}'],
    'empty string' => [''],
]);

it('rejects constructing a mask that fails validation', function (): void {
    new InvoiceNumberMask('{YYYY}');
})->throws(InvalidArgumentException::class);

it('floors the next sequence using the configured start', function (int $lastUsed, int $start, int $expected): void {
    $next = max($lastUsed, max(1, $start) - 1) + 1;

    expect($next)->toBe($expected);
})->with([
    'empty period, migrated start 501' => [0, 501, 501],
    'existing sequence already past start' => [600, 501, 601],
    'default start, empty period' => [0, 1, 1],
]);
