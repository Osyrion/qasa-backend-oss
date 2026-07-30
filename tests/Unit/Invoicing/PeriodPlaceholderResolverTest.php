<?php

declare(strict_types=1);

use App\Modules\Invoicing\Domain\Services\PeriodPlaceholderResolver;
use Carbon\CarbonImmutable;

it('resolves every supported placeholder', function (string $text, string $expected): void {
    $resolver = new PeriodPlaceholderResolver;
    $may = CarbonImmutable::parse('2026-05-31');

    expect($resolver->resolve($text, $may))->toBe($expected);
})->with([
    'BOM' => ['Od {BOM}', 'Od 1.5.2026'],
    'EOM' => ['Do {EOM}', 'Do 31.5.2026'],
    'MONTH' => ['Hosting {MONTH}', 'Hosting 05/2026'],
    'YEAR' => ['Licence {YEAR}', 'Licence 2026'],
    'combined range' => ['Vyúčtování za období {BOM} – {EOM}', 'Vyúčtování za období 1.5.2026 – 31.5.2026'],
]);

it('resolves repeated occurrences of the same placeholder', function (): void {
    $resolver = new PeriodPlaceholderResolver;

    expect($resolver->resolve('{MONTH} a znovu {MONTH}', CarbonImmutable::parse('2026-02-10')))
        ->toBe('02/2026 a znovu 02/2026');
});

it('passes through text without placeholders, null and empty string', function (): void {
    $resolver = new PeriodPlaceholderResolver;
    $date = CarbonImmutable::parse('2026-05-01');

    expect($resolver->resolve('Bez placeholderů', $date))->toBe('Bez placeholderů')
        ->and($resolver->resolve(null, $date))->toBeNull()
        ->and($resolver->resolve('', $date))->toBe('');
});
