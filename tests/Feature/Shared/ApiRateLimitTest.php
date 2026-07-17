<?php

declare(strict_types=1);

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\RateLimiter;

it('throttles authenticated api requests once the limit is exhausted', function (): void {
    // Shrink the limit so the test doesn't need 60 warm-up requests.
    RateLimiter::for('api', fn (): Limit => Limit::perMinute(2)->by('test'));

    $user = createUser();

    $this->actingAs($user)->getJson('/api/v1/orders')->assertOk();
    $this->actingAs($user)->getJson('/api/v1/orders')->assertOk();

    $this->actingAs($user)
        ->getJson('/api/v1/orders')
        ->assertStatus(429)
        ->assertHeader('Retry-After');
});
