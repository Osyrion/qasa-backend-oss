<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Infrastructure\Clients;

use App\Modules\Invoicing\Application\Contracts\CnbRateClientInterface;
use App\Modules\Shared\Enums\Currency;
use App\Modules\Shared\Support\Decimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Fetches the daily fixing from the public ČNB API.
 * https://api.cnb.cz/cnbapi/exrates/daily?date=YYYY-MM-DD&lang=EN
 */
class CnbApiRateClient implements CnbRateClientInterface
{
    public function fetchRate(Currency $currency, string $date): ?float
    {
        if ($currency === Currency::CZK) {
            return 1.0;
        }

        try {
            $response = Http::baseUrl((string) config('services.cnb.base_url'))
                ->timeout(5)
                ->retry(2, 200, throw: false)
                ->get('/cnbapi/exrates/daily', ['date' => $date, 'lang' => 'EN']);
        } catch (Throwable) {
            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        /** @var list<array<string, mixed>> $rates */
        $rates = (array) $response->json('rates', []);

        foreach ($rates as $row) {
            if (($row['currencyCode'] ?? null) !== $currency->value) {
                continue;
            }

            // ČNB quotes some currencies per 100/1000 units
            $amount = (float) ($row['amount'] ?? 1);
            $rate = (float) ($row['rate'] ?? 0);

            // Feeds straight into money conversions, so the per-unit rate is
            // derived exactly rather than through a float division.
            return $amount > 0 && $rate > 0
                ? (float) (string) Decimal::of($rate)->dividedBy(Decimal::of($amount), 6, RoundingMode::HalfUp)
                : null;
        }

        return null;
    }
}
