<?php

declare(strict_types=1);

use App\Modules\Invoicing\Domain\Enums\TaxDateMode;
use Carbon\CarbonImmutable;

it('keeps DUZP equal to the issue date by default', function (): void {
    $issued = CarbonImmutable::parse('2026-06-01');

    expect(TaxDateMode::IssueDate->resolve($issued)->toDateString())->toBe('2026-06-01');
});

it('resolves DUZP to the last day of the previous month', function (string $issued, string $expected): void {
    expect(TaxDateMode::PreviousMonthEnd->resolve(CarbonImmutable::parse($issued))->toDateString())
        ->toBe($expected);
})->with([
    'typical CZ scenario' => ['2026-06-01', '2026-05-31'],
    'mid-month issue' => ['2026-06-15', '2026-05-31'],
    'January crosses the year' => ['2026-01-05', '2025-12-31'],
    'March after leap February' => ['2028-03-01', '2028-02-29'],
]);
