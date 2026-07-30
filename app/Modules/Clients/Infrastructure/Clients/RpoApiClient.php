<?php

declare(strict_types=1);

namespace App\Modules\Clients\Infrastructure\Clients;

use App\Modules\Clients\Application\Contracts\CompanyRegistryClientInterface;
use App\Modules\Clients\Application\DTOs\CompanyRegistryData;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Slovak Register of Legal Entities (Register právnických osôb, RPO).
 * https://api.statistics.sk/rpo/v1/search?identifier={ico}
 *
 * DIČ / IČ DPH are not reliably exposed by RPO — they are left null and
 * verified separately through VIES.
 */
class RpoApiClient implements CompanyRegistryClientInterface
{
    private const int CACHE_TTL = 86400;

    public function fetchByIco(string $ico): ?CompanyRegistryData
    {
        $ico = trim($ico);

        return Cache::remember("registry:SK:{$ico}", self::CACHE_TTL, function () use ($ico): ?CompanyRegistryData {
            try {
                $response = Http::baseUrl((string) config('services.rpo.base_url'))
                    ->timeout(10)
                    ->retry(2, 200, throw: false)
                    ->get('/rpo/v1/search', ['identifier' => $ico]);
            } catch (Throwable) {
                return null;
            }

            if (! $response->successful()) {
                return null;
            }

            /** @var list<array<string, mixed>> $results */
            $results = (array) $response->json('results', []);
            $entity = $results[0] ?? null;

            if (! is_array($entity)) {
                return null;
            }

            /** @var array<string, mixed>|null $address */
            $address = $this->currentEntry($entity['addresses'] ?? null);

            return new CompanyRegistryData(
                company_name: $this->currentValue($entity['fullNames'] ?? null),
                ico: $ico,
                dic: null,
                vat_id: null,
                address: $address !== null ? $this->buildStreet($address) : null,
                city: $address !== null ? $this->str($this->nested($address, 'municipality', 'value')) : null,
                postal_code: $address !== null ? $this->postalCode($address['postalCodes'] ?? null) : null,
                country: ($address !== null ? $this->str($this->nested($address, 'country', 'code')) : null) ?? 'SK',
            );
        });
    }

    /**
     * Latest still-valid (or otherwise last) entry from a historised list.
     *
     * @return array<string, mixed>|null
     */
    private function currentEntry(mixed $list): ?array
    {
        if (! is_array($list) || $list === []) {
            return null;
        }

        $fallback = null;

        foreach ($list as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $fallback = $entry;

            if (($entry['validTo'] ?? null) === null) {
                return $entry;
            }
        }

        return $fallback;
    }

    private function currentValue(mixed $list): ?string
    {
        $entry = $this->currentEntry($list);

        return $entry !== null ? $this->str($entry['value'] ?? null) : null;
    }

    /**
     * @param  array<string, mixed>  $address
     */
    private function buildStreet(array $address): ?string
    {
        $street = $this->str($address['street'] ?? null);
        $number = $this->str($address['buildingNumber'] ?? null) ?? $this->str($address['regNumber'] ?? null);

        return match (true) {
            $street !== null && $number !== null => "{$street} {$number}",
            $street !== null => $street,
            default => $number,
        };
    }

    private function postalCode(mixed $codes): ?string
    {
        if (! is_array($codes) || $codes === []) {
            return null;
        }

        $digits = preg_replace('/\D/', '', (string) reset($codes));

        if ($digits === null || $digits === '') {
            return null;
        }

        return strlen($digits) === 5 ? substr($digits, 0, 3).' '.substr($digits, 3) : $digits;
    }

    private function nested(mixed $source, string ...$keys): mixed
    {
        foreach ($keys as $key) {
            if (! is_array($source) || ! array_key_exists($key, $source)) {
                return null;
            }

            $source = $source[$key];
        }

        return $source;
    }

    private function str(mixed $value): ?string
    {
        if ($value === null || is_array($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
