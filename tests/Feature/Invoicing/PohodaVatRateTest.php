<?php

declare(strict_types=1);

use App\Modules\Invoicing\Domain\Services\PohodaVatRate;

it('maps a zero rate to none', function (): void {
    expect(PohodaVatRate::categoryFor(0.0, 'SK'))->toBe('none');
});

it('maps CZ two-tier rates to low and high', function (): void {
    expect(PohodaVatRate::categoryFor(12.0, 'CZ'))->toBe('low')
        ->and(PohodaVatRate::categoryFor(21.0, 'CZ'))->toBe('high');
});

it('maps SK three-tier rates to low, third and high', function (): void {
    expect(PohodaVatRate::categoryFor(5.0, 'SK'))->toBe('low')
        ->and(PohodaVatRate::categoryFor(10.0, 'SK'))->toBe('third')
        ->and(PohodaVatRate::categoryFor(23.0, 'SK'))->toBe('high');
});

it('falls back to SK rates for an unknown country', function (): void {
    expect(PohodaVatRate::categoryFor(23.0, 'XX'))->toBe('high');
});
