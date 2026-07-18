<?php

declare(strict_types=1);

use App\Modules\Clients\Infrastructure\Clients\ViesApiClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * ViesApiClient's retry(1, ...) means 1 total attempt per verify() call (no
 * actual retry) — these tests still compare the request count before/after
 * the second (cached) call rather than asserting an absolute count, so the
 * assertions don't depend on that retry count.
 */
it('caches a successful lookup and does not call the API again', function (): void {
    Http::fake([
        '*' => Http::response(['isValid' => true, 'name' => 'Acme s.r.o.', 'address' => 'Main St 1'], 200),
    ]);

    $client = new ViesApiClient;
    $first = $client->verify('SK', '2020000000');
    $countAfterFirst = count(Http::recorded());
    $second = $client->verify('SK', '2020000000');

    expect($first?->valid)->toBeTrue()
        ->and($second?->valid)->toBeTrue()
        ->and(count(Http::recorded()))->toBe($countAfterFirst);
});

it('caches a failed lookup so a registry outage does not retry the HTTP call on every request', function (): void {
    Http::fake(['*' => Http::response(null, 500)]);

    $client = new ViesApiClient;
    $first = $client->verify('SK', '2020000001');
    $countAfterFirst = count(Http::recorded());
    $second = $client->verify('SK', '2020000001');

    expect($first)->toBeNull()
        ->and($second)->toBeNull()
        ->and(count(Http::recorded()))->toBe($countAfterFirst);
});

it('retries the API once the failure cache entry has expired', function (): void {
    config(['services.vies.failure_ttl' => 300]);

    // retry(1, ...) means 1 total attempt (no actual retry), so the first
    // (failing) call consumes exactly 1 queued response before the second
    // call falls through to whenEmpty()'s success response.
    Http::fakeSequence()
        ->push(null, 500)
        ->whenEmpty(Http::response(['isValid' => true, 'name' => null, 'address' => null], 200));

    $client = new ViesApiClient;
    $first = $client->verify('SK', '2020000002');
    expect($first)->toBeNull();

    $countBeforeExpiry = count(Http::recorded());

    $this->travel(301)->seconds();
    $result = $client->verify('SK', '2020000002');

    expect($result?->valid)->toBeTrue()
        ->and(count(Http::recorded()))->toBeGreaterThan($countBeforeExpiry);
});

it('treats a network exception as a cacheable failure', function (): void {
    Http::fake(function (): never {
        throw new ConnectionException('timed out');
    });

    $client = new ViesApiClient;
    $first = $client->verify('SK', '2020000003');
    $countAfterFirst = count(Http::recorded());
    $second = $client->verify('SK', '2020000003');

    expect($first)->toBeNull()
        ->and($second)->toBeNull()
        ->and(count(Http::recorded()))->toBe($countAfterFirst);
});

it('caches a well-formed but invalid VAT number as a real (non-failure) result', function (): void {
    Http::fake(['*' => Http::response(['isValid' => false, 'name' => null, 'address' => null], 200)]);

    $client = new ViesApiClient;
    $result = $client->verify('SK', '2020000004');
    $countAfterFirst = count(Http::recorded());

    expect($result)->not->toBeNull()
        ->and($result?->valid)->toBeFalse();

    // A second call for the same number must not hit the API again, since a
    // definitive "not valid" answer is cached at the long TTL, not the
    // short failure_ttl.
    $client->verify('SK', '2020000004');
    expect(count(Http::recorded()))->toBe($countAfterFirst);
});
