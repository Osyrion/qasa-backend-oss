<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Domain\Services;

/**
 * Maps a numeric VAT rate to Pohoda's categorical rateVAT bucket
 * (none/low/third/high). percentVAT is always emitted alongside it with the
 * exact rate, so this categorization only needs to be approximately right —
 * it's derived from config('taxation.*.vat_rates') rather than a separate
 * threshold config, so it can't drift out of sync with the rest of the app.
 */
final class PohodaVatRate
{
    public static function categoryFor(float $rate, string $country): string
    {
        if ($rate <= 0.0) {
            return 'none';
        }

        /** @var list<int|float> $configured */
        $configured = config(
            'taxation.'.strtoupper($country).'.vat_rates',
            config('taxation.SK.vat_rates', []),
        );

        $rates = array_values(array_filter(
            array_map(static fn (int|float $configuredRate): float => (float) $configuredRate, $configured),
            static fn (float $configuredRate): bool => $configuredRate > 0.0,
        ));
        sort($rates);

        $index = array_search($rate, $rates, true);
        $labels = ['low', 'third', 'high'];

        // 2-tier schemes (e.g. CZ: low/high) map the last rate to "high";
        // 3-tier schemes (e.g. SK: low/third/high) use the full ladder.
        return match (count($rates)) {
            2 => $index === 1 ? 'high' : 'low',
            default => $labels[min($index !== false ? (int) $index : 2, 2)],
        };
    }
}
