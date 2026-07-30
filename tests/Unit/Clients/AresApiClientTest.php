<?php

declare(strict_types=1);

use App\Modules\Clients\Infrastructure\Clients\AresApiClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * AresApiClient's retry(2, ...) means 2 total attempts per fetchByIco()
 * call — these tests still compare the request count before/after the
 * second (cached) call rather than asserting an absolute count, so the
 * assertions don't depend on that retry count.
 */
it('caches a successful lookup and does not call the API again', function (): void {
    Http::fake([
        '*' => Http::response([
            'ico' => '12345678',
            'obchodniJmeno' => 'Acme s.r.o.',
            'dic' => 'CZ12345678',
            'sidlo' => ['nazevObce' => 'Praha', 'psc' => 11000, 'kodStatu' => 'CZ'],
        ], 200),
    ]);

    $client = new AresApiClient;
    $first = $client->fetchByIco('12345678');
    $countAfterFirst = count(Http::recorded());
    $second = $client->fetchByIco('12345678');

    expect($first?->ico)->toBe('12345678')
        ->and($second?->ico)->toBe('12345678')
        ->and(count(Http::recorded()))->toBe($countAfterFirst);
});

it('caches a failed lookup so a registry outage does not retry the HTTP call on every request', function (): void {
    Http::fake(['*' => Http::response(null, 500)]);

    $client = new AresApiClient;
    $first = $client->fetchByIco('87654321');
    $countAfterFirst = count(Http::recorded());
    $second = $client->fetchByIco('87654321');

    expect($first)->toBeNull()
        ->and($second)->toBeNull()
        ->and(count(Http::recorded()))->toBe($countAfterFirst);
});

it('retries the API once the failure cache entry has expired', function (): void {
    config(['services.ares.failure_ttl' => 300]);

    // retry(2, ...) means 2 total attempts, so the first (failing) call
    // consumes exactly 2 queued responses before the second call falls
    // through to whenEmpty()'s success response.
    Http::fakeSequence()
        ->push(null, 500)
        ->push(null, 500)
        ->whenEmpty(Http::response(['ico' => '11223344', 'obchodniJmeno' => 'Acme'], 200));

    $client = new AresApiClient;
    $first = $client->fetchByIco('11223344');
    expect($first)->toBeNull();

    $countBeforeExpiry = count(Http::recorded());

    $this->travel(301)->seconds();
    $result = $client->fetchByIco('11223344');

    expect($result?->ico)->toBe('11223344')
        ->and(count(Http::recorded()))->toBeGreaterThan($countBeforeExpiry);
});

it('treats a network exception as a cacheable failure', function (): void {
    Http::fake(function (): never {
        throw new ConnectionException('timed out');
    });

    $client = new AresApiClient;
    $first = $client->fetchByIco('99999999');
    $countAfterFirst = count(Http::recorded());
    $second = $client->fetchByIco('99999999');

    expect($first)->toBeNull()
        ->and($second)->toBeNull()
        ->and(count(Http::recorded()))->toBe($countAfterFirst);
});
