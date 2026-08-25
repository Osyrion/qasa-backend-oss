<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\GoogleProvider;

beforeEach(function (): void {
    // Enough config for Socialite to build the redirect URL without a live app.
    config([
        'services.google.client_id' => 'test-client-id',
        'services.google.client_secret' => 'test-secret',
        'services.google.redirect' => 'http://localhost/auth/google/callback',
        'services.google.mobile_redirect' => 'http://localhost/api/v1/auth/google/callback/mobile',
        'services.google.mobile_app_redirect' => 'flok://auth/google/callback',
    ]);
});

it('issues a one-time state param on the Google redirect url', function (): void {
    $url = $this->getJson('/api/v1/auth/google/redirect')->assertOk()->json('url');

    parse_str((string) parse_url((string) $url, PHP_URL_QUERY), $query);
    $state = $query['state'] ?? null;

    expect($state)->toBeString()->not->toBeEmpty();

    assert(is_string($state));
    expect(Cache::get('google_oauth_state:'.$state))->toBe('web')
        ->and($query['redirect_uri'] ?? null)->toBe('http://localhost/auth/google/callback');
});

it('uses the mobile redirect_uri and tags the state as mobile when platform=mobile', function (): void {
    $url = $this->getJson('/api/v1/auth/google/redirect?platform=mobile')->assertOk()->json('url');

    parse_str((string) parse_url((string) $url, PHP_URL_QUERY), $query);
    $state = $query['state'] ?? null;

    assert(is_string($state));
    // An https URL on this backend, never the app scheme — Google rejects a
    // custom scheme on a Web OAuth client, so the bridge route stands in.
    expect(Cache::get('google_oauth_state:'.$state))->toBe('mobile')
        ->and($query['redirect_uri'] ?? null)->toBe('http://localhost/api/v1/auth/google/callback/mobile');
});

it('rejects an unknown platform value', function (): void {
    $this->getJson('/api/v1/auth/google/redirect?platform=desktop')
        ->assertStatus(422)
        ->assertJsonValidationErrorFor('platform');
});

it('rejects a Google callback that carries no state', function (): void {
    $this->postJson('/api/v1/auth/google/callback', ['code' => 'auth-code'])
        ->assertStatus(422)
        ->assertJsonValidationErrorFor('state');
});

it('rejects a Google callback whose state was never issued', function (): void {
    $this->postJson('/api/v1/auth/google/callback', ['code' => 'auth-code', 'state' => 'never-issued'])
        ->assertStatus(422);
});

it('consumes the state so the same callback cannot be replayed', function (): void {
    Cache::put('google_oauth_state:s1', true, now()->addMinutes(10));

    // Mock the driver so the token exchange never touches the network — the
    // state is pulled before it, so a thrown exchange still proves consumption.
    $driver = Mockery::mock(GoogleProvider::class);
    $driver->shouldReceive('stateless')->andReturnSelf();
    $driver->shouldReceive('user')->andThrow(new RuntimeException('offline'));
    Socialite::shouldReceive('driver')->with('google')->andReturn($driver);

    // First call consumes the state (the mocked exchange then fails → 422).
    $this->postJson('/api/v1/auth/google/callback', ['code' => 'x', 'state' => 's1'])
        ->assertStatus(422);

    expect(Cache::get('google_oauth_state:s1'))->toBeNull();

    // Replay with the same state is now an unknown state → rejected.
    $this->postJson('/api/v1/auth/google/callback', ['code' => 'x', 'state' => 's1'])
        ->assertStatus(422);
});

it('repeats the mobile redirect_uri on the token exchange for a mobile-tagged state', function (): void {
    Cache::put('google_oauth_state:s-mobile', 'mobile', now()->addMinutes(10));

    $driver = Mockery::mock(GoogleProvider::class);
    $driver->shouldReceive('redirectUrl')->once()->with('http://localhost/api/v1/auth/google/callback/mobile')->andReturnSelf();
    $driver->shouldReceive('stateless')->andReturnSelf();
    $driver->shouldReceive('user')->andThrow(new RuntimeException('offline'));
    Socialite::shouldReceive('driver')->with('google')->andReturn($driver);

    $this->postJson('/api/v1/auth/google/callback', ['code' => 'x', 'state' => 's-mobile'])
        ->assertStatus(422);
});

it('bridges the mobile callback on to the app scheme, code and state intact', function (): void {
    $this->get('/api/v1/auth/google/callback/mobile?code=auth-code&state=s-mobile')
        ->assertRedirect('flok://auth/google/callback?code=auth-code&state=s-mobile');
});

it('bridges a denied consent screen too, so the in-app browser closes', function (): void {
    $this->get('/api/v1/auth/google/callback/mobile?error=access_denied')
        ->assertRedirect('flok://auth/google/callback?error=access_denied');
});

it('ignores extra parameters rather than forwarding them to the app', function (): void {
    $this->get('/api/v1/auth/google/callback/mobile?code=c&state=s&next=https://evil.example')
        ->assertRedirect('flok://auth/google/callback?code=c&state=s');
});
