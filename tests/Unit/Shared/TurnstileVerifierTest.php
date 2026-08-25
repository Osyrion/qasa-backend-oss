<?php

declare(strict_types=1);

use App\Modules\Shared\Infrastructure\Clients\TurnstileVerifier;
use Illuminate\Support\Facades\Http;

it('passes any token without calling out when captcha is disabled', function (): void {
    config(['services.turnstile.enabled' => false]);
    Http::fake();

    expect((new TurnstileVerifier)->verify(null, '203.0.113.1'))->toBeTrue();
    Http::assertNothingSent();
});

it('rejects a null token when captcha is enabled', function (): void {
    config(['services.turnstile.enabled' => true]);
    Http::fake();

    expect((new TurnstileVerifier)->verify(null, '203.0.113.1'))->toBeFalse();
    Http::assertNothingSent();
});

it('accepts a token Cloudflare confirms as valid', function (): void {
    config(['services.turnstile.enabled' => true, 'services.turnstile.secret_key' => 'test-secret']);
    Http::fake(['*' => Http::response(['success' => true])]);

    expect((new TurnstileVerifier)->verify('good-token', '203.0.113.1'))->toBeTrue();

    Http::assertSent(fn ($request): bool => $request['secret'] === 'test-secret'
        && $request['response'] === 'good-token'
        && $request['remoteip'] === '203.0.113.1');
});

it('rejects a token Cloudflare reports as invalid', function (): void {
    config(['services.turnstile.enabled' => true, 'services.turnstile.secret_key' => 'test-secret']);
    Http::fake(['*' => Http::response(['success' => false, 'error-codes' => ['invalid-input-response']])]);

    expect((new TurnstileVerifier)->verify('bad-token', '203.0.113.1'))->toBeFalse();
});

it('fails closed when the verification call itself errors', function (): void {
    config(['services.turnstile.enabled' => true, 'services.turnstile.secret_key' => 'test-secret']);
    Http::fake(['*' => Http::response(null, 500)]);

    expect((new TurnstileVerifier)->verify('some-token', '203.0.113.1'))->toBeFalse();
});
