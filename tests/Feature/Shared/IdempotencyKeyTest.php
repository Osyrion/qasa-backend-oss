<?php

declare(strict_types=1);

use App\Modules\Clients\Domain\Models\Client;
use App\Modules\Invoicing\Domain\Models\Invoice;
use App\Modules\Shared\Domain\Models\IdempotencyKey as IdempotencyKeyModel;

it('returns the original response instead of creating a duplicate invoice on retry', function (): void {
    $user = createUser();
    $client = Client::factory()->create(['user_id' => $user->id]);

    $payload = [
        'client_id' => $client->id,
        'issued_at' => today()->toDateString(),
        'due_at' => today()->addDays(14)->toDateString(),
        'currency' => 'EUR',
    ];

    $first = $this->actingAs($user)
        ->withHeaders(['Idempotency-Key' => 'retry-key-1'])
        ->postJson('/api/v1/invoices', $payload);

    $first->assertCreated();
    $firstId = $first->json('data.id');

    $second = $this->actingAs($user)
        ->withHeaders(['Idempotency-Key' => 'retry-key-1'])
        ->postJson('/api/v1/invoices', $payload);

    $second->assertCreated()->assertJsonPath('data.id', $firstId);

    expect(Invoice::where('user_id', $user->id)->count())->toBe(1);
});

it('rejects reuse of the same key with a different body', function (): void {
    $user = createUser();
    $client = Client::factory()->create(['user_id' => $user->id]);

    $this->actingAs($user)
        ->withHeaders(['Idempotency-Key' => 'retry-key-2'])
        ->postJson('/api/v1/invoices', [
            'client_id' => $client->id,
            'issued_at' => today()->toDateString(),
            'due_at' => today()->addDays(14)->toDateString(),
            'currency' => 'EUR',
        ])
        ->assertCreated();

    $conflict = $this->actingAs($user)
        ->withHeaders(['Idempotency-Key' => 'retry-key-2'])
        ->postJson('/api/v1/invoices', [
            'client_id' => $client->id,
            'issued_at' => today()->toDateString(),
            'due_at' => today()->addDays(30)->toDateString(),
            'currency' => 'USD',
        ]);

    $conflict->assertStatus(422);

    expect(Invoice::where('user_id', $user->id)->count())->toBe(1);
});

it('creates two separate invoices when no Idempotency-Key header is sent', function (): void {
    $user = createUser();
    $client = Client::factory()->create(['user_id' => $user->id]);

    $payload = [
        'client_id' => $client->id,
        'issued_at' => today()->toDateString(),
        'due_at' => today()->addDays(14)->toDateString(),
        'currency' => 'EUR',
    ];

    $this->actingAs($user)->postJson('/api/v1/invoices', $payload)->assertCreated();
    $this->actingAs($user)->postJson('/api/v1/invoices', $payload)->assertCreated();

    expect(Invoice::where('user_id', $user->id)->count())->toBe(2);
});

it('scopes the same key to different users independently', function (): void {
    $userA = createUser();
    $userB = createUser();
    $clientA = asAccount($userA, fn () => Client::factory()->create(['user_id' => $userA->id]));
    $clientB = Client::factory()->create(['user_id' => $userB->id]);

    $this->actingAs($userA)
        ->withHeaders(['Idempotency-Key' => 'shared-key'])
        ->postJson('/api/v1/invoices', [
            'client_id' => $clientA->id,
            'issued_at' => today()->toDateString(),
            'due_at' => today()->addDays(14)->toDateString(),
            'currency' => 'EUR',
        ])
        ->assertCreated();

    $this->actingAs($userB)
        ->withHeaders(['Idempotency-Key' => 'shared-key'])
        ->postJson('/api/v1/invoices', [
            'client_id' => $clientB->id,
            'issued_at' => today()->toDateString(),
            'due_at' => today()->addDays(14)->toDateString(),
            'currency' => 'EUR',
        ])
        ->assertCreated();

    // withoutGlobalScope only lifts the Eloquent scope; the policy still
    // applies, so each account's rows are counted while bound to it.
    asAccount($userA, fn () => expect(Invoice::withoutGlobalScope('user')->where('user_id', $userA->id)->count())->toBe(1));
    asAccount($userB, fn () => expect(Invoice::withoutGlobalScope('user')->where('user_id', $userB->id)->count())->toBe(1));
});

it('claims the key before running the handler, so a concurrent retry cannot duplicate the work', function (): void {
    $user = createUser();
    $client = Client::factory()->create(['user_id' => $user->id]);

    $payload = [
        'client_id' => $client->id,
        'issued_at' => today()->toDateString(),
        'due_at' => today()->addDays(14)->toDateString(),
        'currency' => 'EUR',
    ];

    // A second request that arrives while the first is still in flight sees
    // the claim, not an empty table — check-then-act would let both through
    // and only fail the loser's insert, after it had already created a
    // second invoice.
    $this->actingAs($user)
        ->withHeaders(['Idempotency-Key' => 'in-flight-key'])
        ->postJson('/api/v1/invoices', $payload)
        ->assertCreated();

    expect(IdempotencyKeyModel::query()->where('key_hash', '!=', '')->count())->toBe(1);

    // Simulate the in-flight window: the claim exists, no response stored yet.
    IdempotencyKeyModel::query()->update(['response_status' => null, 'response_body' => null]);

    $this->actingAs($user)
        ->withHeaders(['Idempotency-Key' => 'in-flight-key'])
        ->postJson('/api/v1/invoices', $payload)
        ->assertStatus(409);

    expect(Invoice::where('user_id', $user->id)->count())->toBe(1);
});

it('releases the claim when the handler fails, so the request can be retried', function (): void {
    $user = createUser();

    // A validation failure (422) is a legitimate answer and gets stored; a
    // server fault is not, and must not wedge the key forever.
    $this->actingAs($user)
        ->withHeaders(['Idempotency-Key' => 'boom-key'])
        ->postJson('/api/v1/invoices', ['client_id' => 'not-a-uuid'])
        ->assertStatus(422);

    $client = Client::factory()->create(['user_id' => $user->id]);

    // Same key, different body — the stored 422 is a real response, so this
    // is the documented conflict, not a wedge.
    $this->actingAs($user)
        ->withHeaders(['Idempotency-Key' => 'boom-key'])
        ->postJson('/api/v1/invoices', [
            'client_id' => $client->id,
            'issued_at' => today()->toDateString(),
            'due_at' => today()->addDays(14)->toDateString(),
            'currency' => 'EUR',
        ])
        ->assertStatus(422);
});

it('lets an expired key be claimed again', function (): void {
    $user = createUser();
    $client = Client::factory()->create(['user_id' => $user->id]);

    $payload = [
        'client_id' => $client->id,
        'issued_at' => today()->toDateString(),
        'due_at' => today()->addDays(14)->toDateString(),
        'currency' => 'EUR',
    ];

    $this->actingAs($user)
        ->withHeaders(['Idempotency-Key' => 'stale-key'])
        ->postJson('/api/v1/invoices', $payload)
        ->assertCreated();

    // Past the 24h TTL the stored response is no longer replayable — but the
    // unique key_hash still occupies the slot, so the claim has to displace
    // it rather than collide with it.
    IdempotencyKeyModel::query()->update(['created_at' => now()->subHours(25)]);

    $this->actingAs($user)
        ->withHeaders(['Idempotency-Key' => 'stale-key'])
        ->postJson('/api/v1/invoices', $payload)
        ->assertCreated();

    expect(Invoice::where('user_id', $user->id)->count())->toBe(2);
});
