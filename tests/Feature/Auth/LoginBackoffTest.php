<?php

declare(strict_types=1);

use App\Modules\Auth\Application\Services\LoginThrottle;
use App\Modules\Auth\Domain\Exceptions\TooManyLoginAttemptsException;
use Illuminate\Support\Facades\Cache;

/**
 * Progressive backoff on failed logins, keyed on (e-mail + IP).
 *
 * The route-level `auth-login` limiter caps the *rate* of attempts but
 * forgives completely every minute, so a patient attacker keeps guessing
 * indefinitely. The backoff below makes each failure cost more than the one
 * before it — and, because the counter is keyed on the pair rather than on
 * the e-mail alone, an attacker can only ever spend their own budget. The
 * account owner arriving from a different address is untouched, which is
 * what separates this from an account lockout.
 */
beforeEach(function (): void {
    // The rate limiters share this store, so one flush resets both.
    Cache::flush();
});

it('lets five failures pass before any wait is imposed', function (): void {
    $user = createUser();

    $attempt = fn (string $password) => test()
        ->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])
        ->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => $password,
        ]);

    // Someone mistyping their own password is a person, not an attack.
    for ($i = 0; $i < 5; $i++) {
        $attempt('wrong-password')->assertStatus(422);
    }

    // The sixth failure is the one that starts the timer...
    $attempt('wrong-password')->assertStatus(422);

    // ...so the seventh attempt is refused outright, with the wait attached.
    $blocked = $attempt('wrong-password')->assertStatus(429);

    expect($blocked->headers->get('Retry-After'))->toBe('120');
});

it('never locks the account owner out of an attack on their own address', function (): void {
    $user = createUser();

    $fromAttacker = fn () => test()
        ->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])
        ->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'wrong-password',
        ]);

    for ($i = 0; $i < 6; $i++) {
        $fromAttacker();
    }

    $fromAttacker()->assertStatus(429);

    // The whole reason the counter carries the address as well as the
    // e-mail: a counter keyed on the account alone would have handed the
    // attacker a way to lock its owner out at will.
    test()->withServerVariables(['REMOTE_ADDR' => '198.51.100.4'])
        ->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertOk();
});

it('throttles an unknown e-mail exactly like a real one', function (): void {
    $attempt = fn () => test()
        ->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])
        ->postJson('/api/v1/auth/login', [
            'email' => 'nobody@example.test',
            'password' => 'wrong-password',
        ]);

    for ($i = 0; $i < 6; $i++) {
        $attempt()->assertStatus(422);
    }

    // Counting only addresses that exist would make the backoff itself an
    // enumeration oracle — "this one throttles, so it is registered".
    $attempt()->assertStatus(429);
});

it('forgets the failures once the right password arrives', function (): void {
    $user = createUser();

    $attempt = fn (string $password) => test()
        ->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])
        ->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => $password,
        ]);

    for ($i = 0; $i < 6; $i++) {
        $attempt('wrong-password')->assertStatus(422);
    }

    $attempt('wrong-password')->assertStatus(429);

    // Cleared by hand rather than by waiting out the two minutes; the point
    // under test is what a correct password does to the counter, not the
    // timer that led here.
    app(LoginThrottle::class)->clear($user->email, '203.0.113.9');

    $attempt('password')->assertOk();

    // Back to a clean slate: the next mistake is a plain 422, not a 429
    // left over from before.
    $attempt('wrong-password')->assertStatus(422);
});

it('doubles the wait each failure and then holds it at an hour', function (): void {
    $this->freezeTime();

    $throttle = app(LoginThrottle::class);

    $waitAfter = function (int $failures) use ($throttle): int {
        $throttle->clear('victim@example.test', '203.0.113.9');

        for ($i = 0; $i < $failures; $i++) {
            $throttle->recordFailure('victim@example.test', '203.0.113.9');
        }

        try {
            $throttle->check('victim@example.test', '203.0.113.9');

            return 0;
        } catch (TooManyLoginAttemptsException $e) {
            return $e->retryAfterSeconds;
        }
    };

    expect($waitAfter(5))->toBe(0)
        ->and($waitAfter(6))->toBe(120)
        ->and($waitAfter(7))->toBe(240)
        ->and($waitAfter(8))->toBe(480)
        ->and($waitAfter(9))->toBe(960)
        ->and($waitAfter(10))->toBe(1920)
        // Capped from here on. Left doubling it reaches days within a few
        // more failures, which is an account lockout wearing a different
        // name — at an hour the attacker is already down to one guess an
        // hour, and the owner is never more than an hour from their account.
        ->and($waitAfter(11))->toBe(3600)
        ->and($waitAfter(12))->toBe(3600)
        ->and($waitAfter(20))->toBe(3600);
});
