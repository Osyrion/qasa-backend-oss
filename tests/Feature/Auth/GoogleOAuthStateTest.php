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
    ]);
});

it('issues a one-time state param on the Google redirect url', function (): void {
    $url = $this->getJson('/api/v1/auth/google/redirect')->assertOk()->json('url');

    parse_str((string) parse_url((string) $url, PHP_URL_QUERY), $query);
    $state = $query['state'] ?? null;

    expect($state)->toBeString()->not->toBeEmpty();

    assert(is_string($state));
    expect(Cache::get('google_oauth_state:'.$state))->toBeTrue();
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
