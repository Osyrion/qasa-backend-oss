<?php

declare(strict_types=1);

namespace App\Modules\Auth\Application\Services;

use App\Modules\Auth\Domain\Exceptions\CaptchaRequiredException;
use App\Modules\Shared\Application\Contracts\CaptchaVerifierInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Second layer over LoginThrottle, and the one that sees what it cannot.
 *
 * The backoff is keyed on (e-mail + IP) so that an attacker can never spend
 * the account owner's budget — the price being that an attacker who rotates
 * addresses gets a fresh bucket with every one. This counter is keyed on the
 * account alone and so watches exactly that case.
 *
 * Because it *is* keyed on the account alone, anyone can trip it for anyone.
 * That is survivable only because tripping it escalates friction instead of
 * refusing: the next attempt has to carry a solved captcha, which costs an
 * attacker a challenge per guess — enough to make distributed guessing
 * pointless — and costs the owner one challenge, once. Nobody is ever shut
 * out of their own account, which is the line this whole design holds.
 *
 * On a deployment that never configured Turnstile the gate disappears:
 * CaptchaVerifierInterface::verify() answers true when captcha is switched
 * off, so nothing is ever demanded. Demanding a challenge that cannot render
 * would be a real lockout.
 */
class LoginCaptchaGate
{
    /**
     * Failures against one account, from anywhere, before a challenge is
     * asked for. Comfortably past what the per-pair backoff lets a single
     * address spend, so reaching it means the attempts were spread out.
     */
    private const THRESHOLD = 10;

    /** Counter lifetime, measured from the last failure. */
    private const DECAY_SECONDS = 3600;

    private const PREFIX = 'login-captcha:';

    public function __construct(
        private readonly CaptchaVerifierInterface $verifier,
    ) {}

    /**
     * @throws CaptchaRequiredException
     */
    public function ensureSolved(string $email, ?string $token, ?string $ip): void
    {
        if ($this->failures($email) < self::THRESHOLD) {
            return;
        }

        if ($this->verifier->verify($token, $ip)) {
            return;
        }

        throw CaptchaRequiredException::forLogin();
    }

    public function recordFailure(string $email): void
    {
        // Rewritten with a full TTL each time, so the window runs from the
        // last failure rather than the first.
        Cache::put($this->key($email), $this->failures($email) + 1, self::DECAY_SECONDS);
    }

    public function clear(string $email): void
    {
        Cache::forget($this->key($email));
    }

    private function failures(string $email): int
    {
        return (int) Cache::get($this->key($email), 0);
    }

    private function key(string $email): string
    {
        // Hashed for the same reason LoginThrottle hashes its own: the cache
        // is a Postgres table by default, and a bare e-mail in it is personal
        // data outliving the account it belongs to.
        return self::PREFIX.hash('sha256', Str::lower(trim($email)));
    }
}
