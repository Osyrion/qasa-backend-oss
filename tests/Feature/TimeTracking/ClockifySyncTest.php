<?php

declare(strict_types=1);

use App\Modules\Auth\Domain\Models\User;
use App\Modules\Orders\Domain\Models\Order;
use App\Modules\TimeTracking\Domain\Models\TimeEntry;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;

/** @return array{0: User, 1: Order} */
function clockifyScope(): array
{
    $user = createUser([
        'clockify_api_key' => 'test-key',
        'clockify_workspace_id' => 'ws-1',
    ]);
    $order = Order::factory()->create(['user_id' => $user->id]);

    return [$user, $order];
}

/** @param list<array<string, mixed>> $entries */
function fakeClockify(array $entries): void
{
    Http::fake([
        'api.clockify.me/api/v1/user' => Http::response([
            'id' => 'cl-user-1',
            'activeWorkspace' => 'ws-1',
        ]),
        'api.clockify.me/api/v1/workspaces/*' => Http::response($entries),
    ]);
}

/** @return array<string, mixed> */
function clockifyEntry(string $id, string $description, string $start, ?string $end): array
{
    return [
        'id' => $id,
        'description' => $description,
        'billable' => true,
        'timeInterval' => ['start' => $start, 'end' => $end],
    ];
}

/** @return TestResponse<Response> */
function syncClockify(object $test, User $user, Order $order): TestResponse
{
    return $test->actingAs($user)->postJson('/api/v1/time-entries/sync/clockify', [
        'order_id' => $order->id,
        'date_from' => today()->subDays(7)->toDateString(),
        'date_to' => today()->toDateString(),
    ]);
}

it('imports finished Clockify entries into the order', function (): void {
    [$user, $order] = clockifyScope();

    fakeClockify([
        clockifyEntry('e1', 'Programovanie', '2026-07-01T09:00:00Z', '2026-07-01T11:00:00Z'),
        clockifyEntry('e2', 'Konzultácia', '2026-07-02T13:00:00Z', '2026-07-02T14:30:00Z'),
    ]);

    $response = syncClockify($this, $user, $order);

    $response->assertOk()
        ->assertJson(['created' => 2, 'updated' => 0, 'skipped' => 0]);

    $entry = TimeEntry::withoutGlobalScope('user')->where('external_id', 'e1')->first();

    expect($entry)->not->toBeNull()
        ->and($entry?->source)->toBe('clockify')
        ->and($entry?->order_id)->toBe($order->id)
        ->and($entry?->duration_seconds)->toBe(7200);
});

it('is idempotent on re-sync', function (): void {
    [$user, $order] = clockifyScope();

    fakeClockify([
        clockifyEntry('e1', 'Programovanie', '2026-07-01T09:00:00Z', '2026-07-01T11:00:00Z'),
    ]);

    syncClockify($this, $user, $order)->assertJson(['created' => 1]);
    syncClockify($this, $user, $order)->assertJson(['created' => 0, 'updated' => 1]);

    expect(TimeEntry::withoutGlobalScope('user')->count())->toBe(1);
});

it('skips running entries', function (): void {
    [$user, $order] = clockifyScope();

    fakeClockify([
        clockifyEntry('running', 'Ešte bežím', '2026-07-01T09:00:00Z', null),
    ]);

    syncClockify($this, $user, $order)
        ->assertJson(['created' => 0, 'skipped' => 1]);
});

it('leaves already invoiced entries untouched', function (): void {
    [$user, $order] = clockifyScope();

    TimeEntry::factory()->create([
        'user_id' => $user->id,
        'order_id' => $order->id,
        'source' => 'clockify',
        'external_id' => 'e1',
        'description' => 'Pôvodný popis',
        'is_invoiced' => true,
    ]);

    fakeClockify([
        clockifyEntry('e1', 'Zmenený popis', '2026-07-01T09:00:00Z', '2026-07-01T11:00:00Z'),
    ]);

    syncClockify($this, $user, $order)->assertJson(['skipped' => 1]);

    expect(TimeEntry::withoutGlobalScope('user')->where('external_id', 'e1')->value('description'))
        ->toBe('Pôvodný popis');
});

it('rejects syncing without a configured API key', function (): void {
    $user = createUser(['clockify_api_key' => null]);
    $order = Order::factory()->create(['user_id' => $user->id]);

    syncClockify($this, $user, $order)->assertUnprocessable();
});

it('rejects syncing into another user\'s order', function (): void {
    [$user] = clockifyScope();
    $foreignOrder = Order::factory()->create(['user_id' => createUser()->id]);

    fakeClockify([]);

    // Scoped order lookup 404s foreign orders
    syncClockify($this, $user, $foreignOrder)->assertNotFound();
});
