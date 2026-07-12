<?php

declare(strict_types=1);

use App\Modules\Orders\Domain\Models\Order;
use App\Modules\TimeTracking\Domain\Models\TimeEntry;
use Illuminate\Http\UploadedFile;

/**
 * @param  list<string>  $lines
 */
function fakeTimeEntryCsvUpload(array $lines): UploadedFile
{
    $path = tempnam(sys_get_temp_dir(), 'time_entries').'.csv';
    file_put_contents($path, implode("\n", $lines));

    return new UploadedFile($path, 'time_entries.csv', 'text/csv', null, true);
}

it('imports time entries from a Toggl CSV export', function (): void {
    $user = createUser();
    $order = Order::factory()->create(['user_id' => $user->id]);

    $file = fakeTimeEntryCsvUpload([
        'User,Project,Description,Start date,Start time,End date,End time,Billable',
        'Jan,Website,Design work,07/01/2026,09:00,07/01/2026,11:00,Yes',
    ]);

    $response = $this->actingAs($user)
        ->postJson('/api/v1/time-entries/import/csv', ['file' => $file, 'order_id' => $order->id])
        ->assertOk();

    expect($response->json('created'))->toBe(1)
        ->and($response->json('skipped'))->toBe(0);

    $entry = TimeEntry::query()->where('user_id', $user->id)->firstOrFail();

    expect($entry->source)->toBe('toggl')
        ->and($entry->order_id)->toBe($order->id)
        ->and($entry->duration_seconds)->toBe(7200);
});

it('imports time entries from a Clockify CSV export', function (): void {
    $user = createUser();
    $order = Order::factory()->create(['user_id' => $user->id]);

    $file = fakeTimeEntryCsvUpload([
        'Email,Project,Task,Description,Start Date,Start Time,End Date,End Time',
        'jan@example.com,Website,Dev,Backend work,07/01/2026,09:00,07/01/2026,10:30',
    ]);

    $response = $this->actingAs($user)
        ->postJson('/api/v1/time-entries/import/csv', ['file' => $file, 'order_id' => $order->id])
        ->assertOk();

    expect($response->json('created'))->toBe(1);

    $entry = TimeEntry::query()->where('user_id', $user->id)->firstOrFail();

    expect($entry->source)->toBe('clockify')
        ->and($entry->order_id)->toBe($order->id);
});

it('skips duplicate rows on re-import', function (): void {
    $user = createUser();
    $order = Order::factory()->create(['user_id' => $user->id]);

    $lines = [
        'User,Project,Description,Start date,Start time,End date,End time,Billable',
        'Jan,Website,Design work,07/01/2026,09:00,07/01/2026,11:00,Yes',
    ];

    $this->actingAs($user)
        ->postJson('/api/v1/time-entries/import/csv', ['file' => fakeTimeEntryCsvUpload($lines), 'order_id' => $order->id])
        ->assertOk();

    $response = $this->actingAs($user)
        ->postJson('/api/v1/time-entries/import/csv', ['file' => fakeTimeEntryCsvUpload($lines), 'order_id' => $order->id])
        ->assertOk();

    expect($response->json('created'))->toBe(0)
        ->and($response->json('skipped'))->toBe(1);

    expect(TimeEntry::query()->where('user_id', $user->id)->count())->toBe(1);
});

it('rejects an unrecognised CSV format', function (): void {
    $user = createUser();
    $order = Order::factory()->create(['user_id' => $user->id]);

    $file = fakeTimeEntryCsvUpload([
        'foo,bar',
        'baz,qux',
    ]);

    $this->actingAs($user)
        ->postJson('/api/v1/time-entries/import/csv', ['file' => $file, 'order_id' => $order->id])
        ->assertUnprocessable();
});

it('returns 404 when importing into another account\'s order', function (): void {
    $user = createUser();
    $foreignOrder = Order::factory()->create(['user_id' => createUser()->id]);

    $file = fakeTimeEntryCsvUpload([
        'User,Project,Description,Start date,Start time,End date,End time,Billable',
        'Jan,Website,Design work,07/01/2026,09:00,07/01/2026,11:00,Yes',
    ]);

    $this->actingAs($user)
        ->postJson('/api/v1/time-entries/import/csv', ['file' => $file, 'order_id' => $foreignOrder->id])
        ->assertNotFound();
});
