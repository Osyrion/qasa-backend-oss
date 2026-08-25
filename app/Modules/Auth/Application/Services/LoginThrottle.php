<?php

declare(strict_types=1);

namespace App\Modules\Auth\Application\Services;

use App\Modules\Auth\Domain\Exceptions\TooManyLoginAttemptsException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Progressive backoff on failed logins, keyed on (e-mail + IP).
 *
 * The pair is the whole design. A counter keyed on the e-mail alone bounds a
 * distributed attack but hands anyone a way to lock any account out by
 * failing logins at its address — an account lockout wearing a different
 * name. One keyed on the address alone never sees a botnet spread its
 * attempts. Keyed on both, an attacker only ever spends their own budget and
 * the owner, arriving from somewhere else, is never affected.
 *
 * What this deliberately does not do is bound an attacker who rotates
 * addresses: every new one is a fresh bucket. That case belongs to the
 * per-account limiter next to it (AppServiceProvider's `auth-login`), and to
 * the captcha challenge that is meant to follow.
 *
 * It complements the route-level limiter rather than replacing it. That one
 * caps the rate but forgives completely every minute, so a patient attacker
 * keeps guessing forever; this one makes each failure cost more than the one
 * before it, and remembers for a day.
 */
class LoginThrottle
{
    /**
     * Failures that cost nothing. A password typed wrong a few times is
     * someone who owns the account, not someone attacking it.
     */
    private const FREE_ATTEMPTS = 5;

    /** The wait after the first failure past the free ones. Doubles after that. */
    private const BASE_SECONDS = 120;

    /**
     * Ceiling on the doubling. Uncapped it passes a day within a few more
     * failures, which puts the owner back where the (e-mail + IP) keying
     * exists to keep them out of. An hour already has the attacker down to
     * one guess an hour.
     */
    private const MAX_SECONDS = 3600;

    /** Counter lifetime, measured from the last failure — see recordFailure(). */
    private const DECAY_SECONDS = 86400;

    private const PREFIX = 'login-throttle:';

    /**
     * @throws TooManyLoginAttemptsException
     */
    public function check(string $email, ?string $ip): void
    {
        $remaining = $this->state($email, $ip)['until'] - $this->now();

        if ($remaining > 0) {
            throw TooManyLoginAttemptsException::retryAfter($remaining);
        }
    }

    public function recordFailure(string $email, ?string $ip): void
    {
        $count = $this->state($email, $ip)['count'] + 1;
        $delay = $this->delayFor($count);

        // Written back with a full TTL every time, which is what makes the
        // decay window run from the *last* failure rather than the first.
        //
        // Read-then-write rather than an atomic increment: two attempts
        // racing can cost a lost failure. That is bounded to near nothing
        // here, because a bucket belongs to one address and that address is
        // already capped at ten attempts a minute by the route limiter.
        Cache::put($this->key($email, $ip), [
            'count' => $count,
            'until' => $delay > 0 ? $this->now() + $delay : 0,
        ], self::DECAY_SECONDS);
    }

    public function clear(string $email, ?string $ip): void
    {
        Cache::forget($this->key($email, $ip));
    }

    private function delayFor(int $count): int
    {
        if ($count <= self::FREE_ATTEMPTS) {
            return 0;
        }

        // Bounded before the shift rather than after, so a long-running
        // attack cannot walk 2 ** $n into a float and out of integer range.
        $doublings = min($count - self::FREE_ATTEMPTS - 1, 20);

        return (int) min(self::BASE_SECONDS * (2 ** $doublings), self::MAX_SECONDS);
    }

    /**
     * @return array{count: int, until: int}
     */
    private function state(string $email, ?string $ip): array
    {
        $stored = Cache::get($this->key($email, $ip));

        if (! is_array($stored)) {
            return ['count' => 0, 'until' => 0];
        }

        return [
            'count' => (int) ($stored['count'] ?? 0),
            'until' => (int) ($stored['until'] ?? 0),
        ];
    }

    private function key(string $email, ?string $ip): string
    {
        // Hashed, not stored raw: the cache is a Postgres table by default
        // (CACHE_STORE=database), and a bare e-mail in it is personal data
        // living outside the account it belongs to, untouched by the
        // account's own deletion.
        return self::PREFIX.hash('sha256', Str::lower(trim($email)).'|'.($ip ?? 'unknown'));
    }

    private function now(): int
    {
        return now()->getTimestamp();
    }
}
