<?php

declare(strict_types=1);

use App\Modules\Shared\Domain\Models\IdempotencyKey;

it('purges idempotency key records older than the 24h TTL', function (): void {
    $user = createUser();

    $old = IdempotencyKey::create([
        'user_id' => $user->id,
        'key_hash' => 'old-hash',
        'body_hash' => 'body-hash',
        'response_status' => 201,
        'response_body' => ['id' => 'x'],
    ]);
    $old->created_at = now()->subHours(25);
    $old->save();

    $recent = IdempotencyKey::create([
        'user_id' => $user->id,
        'key_hash' => 'recent-hash',
        'body_hash' => 'body-hash',
        'response_status' => 201,
        'response_body' => ['id' => 'y'],
    ]);
    $recent->created_at = now()->subHours(1);
    $recent->save();

    $this->artisan('qasa:idempotency-keys:purge')->assertSuccessful();

    expect(IdempotencyKey::query()->find($old->id))->toBeNull()
        ->and(IdempotencyKey::query()->find($recent->id))->not->toBeNull();
});
