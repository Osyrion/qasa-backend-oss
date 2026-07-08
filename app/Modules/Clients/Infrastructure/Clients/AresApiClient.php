<?php

declare(strict_types=1);

namespace App\Modules\Clients\Infrastructure\Clients;

use App\Modules\Clients\Application\Contracts\CompanyRegistryClientInterface;
use App\Modules\Clients\Application\DTOs\CompanyRegistryData;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Czech company register (ARES) REST API.
 * https://ares.gov.cz/ekonomicke-subjekty-v-be/rest/ekonomicke-subjekty/{ico}
 */
class AresApiClient implements CompanyRegistryClientInterface
{
    private const int CACHE_TTL = 86400;

    public function fetchByIco(string $ico): ?CompanyRegistryData
    {
        $ico = trim($ico);

        return Cache::remember("registry:CZ:{$ico}", self::CACHE_TTL, function () use ($ico): ?CompanyRegistryData {
            try {
                $response = Http::baseUrl((string) config('services.ares.base_url'))
                    ->timeout(10)
                    ->retry(2, 200, throw: false)
                    ->get("/ekonomicke-subjekty-v-be/rest/ekonomicke-subjekty/{$ico}");
            } catch (Throwable) {
                return null;
            }

            if (! $response->successful() || $response->json('ico') === null) {
                return null;
            }

            /** @var array<string, mixed> $sidlo */
            $sidlo = (array) $response->json('sidlo', []);
            $dic = $response->json('dic');

            return new CompanyRegistryData(
                company_name: $this->str($response->json('obchodniJmeno')),
                ico: $this->str($response->json('ico')),
                dic: $this->str($dic),
                // In Czechia the DIČ is the VAT identification number.
                vat_id: $this->str($dic),
                address: $this->buildStreet($sidlo),
                city: $this->str($sidlo['nazevObce'] ?? null),
                postal_code: $this->postalCode($sidlo['psc'] ?? null),
                country: $this->str($sidlo['kodStatu'] ?? null) ?? 'CZ',
            );
        });
    }

    /**
     * @param  array<string, mixed>  $sidlo
     */
    private function buildStreet(array $sidlo): ?string
    {
        $street = $this->str($sidlo['nazevUlice'] ?? null) ?? $this->str($sidlo['nazevObce'] ?? null);

        if ($street === null) {
            return $this->str($sidlo['textovaAdresa'] ?? null);
        }

        $house = $this->str($sidlo['cisloDomovni'] ?? null);
        $orientation = $this->str($sidlo['cisloOrientacni'] ?? null);

        $number = match (true) {
            $house !== null && $orientation !== null => "{$house}/{$orientation}",
            default => $house ?? $orientation,
        };

        return $number === null ? $street : "{$street} {$number}";
    }

    private function postalCode(mixed $psc): ?string
    {
        if ($psc === null) {
            return null;
        }

        $digits = preg_replace('/\D/', '', (string) $psc);

        if ($digits === null || $digits === '') {
            return null;
        }

        return strlen($digits) === 5 ? substr($digits, 0, 3).' '.substr($digits, 3) : $digits;
    }

    private function str(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
