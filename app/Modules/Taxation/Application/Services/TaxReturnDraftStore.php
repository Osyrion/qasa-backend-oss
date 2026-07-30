<?php

declare(strict_types=1);

namespace App\Modules\Taxation\Application\Services;

use App\Modules\Shared\Exceptions\DomainException;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use JsonException;

/**
 * Short-lived, encrypted wizard progress — never touches the database.
 * Personal circumstances (spouse, children) don't belong in the app DB or
 * logs, so this is a cache record, TTL 7 days, keyed per (user, year).
 */
class TaxReturnDraftStore
{
    private const TTL_DAYS = 7;

    private const PREFIX = 'tax-return:draft:';

    /**
     * @param  array<string, mixed>  $payload
     */
    public function put(string $userId, int $year, array $payload): void
    {
        Cache::put(
            $this->key($userId, $year),
            Crypt::encryptString(json_encode($payload, JSON_THROW_ON_ERROR)),
            now()->addDays(self::TTL_DAYS),
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function get(string $userId, int $year): ?array
    {
        $encrypted = Cache::get($this->key($userId, $year));

        if (! is_string($encrypted)) {
            return null;
        }

        try {
            /** @var array<string, mixed> $decoded */
            $decoded = json_decode(Crypt::decryptString($encrypted), true, flags: JSON_THROW_ON_ERROR);

            return $decoded;
        } catch (JsonException|DecryptException) {
            throw DomainException::because(__('taxation.invalid_draft_payload'));
        }
    }

    public function forget(string $userId, int $year): void
    {
        Cache::forget($this->key($userId, $year));
    }

    private function key(string $userId, int $year): string
    {
        return self::PREFIX.$userId.':'.$year;
    }
}
