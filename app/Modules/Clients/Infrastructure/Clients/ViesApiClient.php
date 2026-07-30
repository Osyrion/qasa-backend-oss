<?php

declare(strict_types=1);

namespace App\Modules\Clients\Infrastructure\Clients;

use App\Modules\Clients\Application\Contracts\VatValidatorInterface;
use App\Modules\Clients\Application\DTOs\VatValidationData;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * EU VIES VAT number validation REST API.
 * https://ec.europa.eu/taxation_customs/vies/rest-api/ms/{country}/vat/{number}
 */
class ViesApiClient implements VatValidatorInterface
{
    private const int CACHE_TTL = 21600;

    /**
     * Cache::remember() never writes null, so a registry outage would make
     * every request pay the full timeout+retry again. This sentinel marks
     * a failed lookup in the cache (short TTL) so it degrades to today's
     * null (fallback to manual entry) without repeating the HTTP call.
     */
    private const string FAILURE_SENTINEL = '__failed';

    public function verify(string $countryCode, string $vatNumber): ?VatValidationData
    {
        $countryCode = strtoupper(trim($countryCode));
        $number = $this->normaliseNumber($countryCode, $vatNumber);
        $cacheKey = "vies:{$countryCode}:{$number}";

        $cached = Cache::get($cacheKey);

        if ($cached === self::FAILURE_SENTINEL) {
            return null;
        }

        if ($cached !== null) {
            /** @var VatValidationData $cached */
            return $cached;
        }

        $result = $this->fetch($countryCode, $number);

        Cache::put(
            $cacheKey,
            $result ?? self::FAILURE_SENTINEL,
            $result !== null ? self::CACHE_TTL : (int) config('services.vies.failure_ttl', 300),
        );

        return $result;
    }

    private function fetch(string $countryCode, string $number): ?VatValidationData
    {
        try {
            $response = Http::baseUrl((string) config('services.vies.base_url'))
                ->timeout(15)
                ->retry(1, 300, throw: false)
                ->get("/ms/{$countryCode}/vat/{$number}");
        } catch (Throwable) {
            return null;
        }

        if (! $response->successful() || $response->json('isValid') === null) {
            return null;
        }

        return new VatValidationData(
            valid: (bool) $response->json('isValid'),
            country: $countryCode,
            vat_number: $countryCode.$number,
            name: $this->str($response->json('name')),
            address: $this->str($response->json('address')),
        );
    }

    private function normaliseNumber(string $countryCode, string $vatNumber): string
    {
        $vatNumber = strtoupper(preg_replace('/\s+/', '', $vatNumber) ?? '');

        return str_starts_with($vatNumber, $countryCode)
            ? substr($vatNumber, strlen($countryCode))
            : $vatNumber;
    }

    private function str(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' || $value === '---' ? null : $value;
    }
}
