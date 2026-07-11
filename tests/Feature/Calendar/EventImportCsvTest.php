<?php

declare(strict_types=1);

use App\Modules\Calendar\Domain\Enums\EventSource;
use App\Modules\Calendar\Domain\Models\Event;
use Illuminate\Http\UploadedFile;

/**
 * @param  list<string>  $lines
 */
function fakeCsvUpload(array $lines): UploadedFile
{
    $path = tempnam(sys_get_temp_dir(), 'events').'.csv';
    file_put_contents($path, implode("\n", $lines));

    return new UploadedFile($path, 'events.csv', 'text/csv', null, true);
}

it('imports events from a CSV file', function (): void {
    $user = createUser();

    $file = fakeCsvUpload([
        'title;description;location;color;is_all_day;starts_at;ends_at',
        'Standup;Daily sync;Office;;0;2026-08-03 10:00;2026-08-03 10:15',
        'Retro;;Office;;0;2026-08-04 10:00;2026-08-04 11:00',
    ]);

    $response = $this->actingAs($user)
        ->postJson('/api/v1/events/import/csv', ['file' => $file])
        ->assertOk();

    expect($response->json('created'))->toBe(2)
        ->and($response->json('skipped'))->toBe(0)
        ->and($response->json('errors'))->toBe([]);

    expect(Event::query()->count())->toBe(2);
    expect(Event::query()->first())
        ->source->toBe(EventSource::CsvImport)
        ->external_uid->not->toBeNull();
});

it('skips duplicate rows on re-import', function (): void {
    $user = createUser();

    $file = fakeCsvUpload([
        'title;description;location;color;is_all_day;starts_at;ends_at',
        'Standup;;;;0;2026-08-03 10:00;2026-08-03 10:15',
    ]);

    $this->actingAs($user)->postJson('/api/v1/events/import/csv', ['file' => $file])->assertOk();

    $secondUpload = fakeCsvUpload([
        'title;description;location;color;is_all_day;starts_at;ends_at',
        'Standup;;;;0;2026-08-03 10:00;2026-08-03 10:15',
    ]);

    $response = $this->actingAs($user)
        ->postJson('/api/v1/events/import/csv', ['file' => $secondUpload])
        ->assertOk();

    expect($response->json('created'))->toBe(0)
        ->and($response->json('skipped'))->toBe(1);

    expect(Event::query()->count())->toBe(1);
});

it('collects errors for invalid rows while importing the rest', function (): void {
    $user = createUser();

    $file = fakeCsvUpload([
        'title;description;location;color;is_all_day;starts_at;ends_at',
        'Bad row;;;;0;not-a-date;also-not-a-date',
        'Good row;;;;0;2026-08-03 10:00;2026-08-03 10:15',
    ]);

    $response = $this->actingAs($user)
        ->postJson('/api/v1/events/import/csv', ['file' => $file])
        ->assertOk();

    expect($response->json('created'))->toBe(1)
        ->and($response->json('errors'))->toHaveCount(1);
});

it('rejects a CSV with unrecognized headers', function (): void {
    $user = createUser();

    $file = fakeCsvUpload([
        'foo;bar',
        'baz;qux',
    ]);

    $this->actingAs($user)
        ->postJson('/api/v1/events/import/csv', ['file' => $file])
        ->assertUnprocessable();
});

it('does not import into another tenant', function (): void {
    $owner = createUser();
    $other = createUser();

    $file = fakeCsvUpload([
        'title;description;location;color;is_all_day;starts_at;ends_at',
        'Standup;;;;0;2026-08-03 10:00;2026-08-03 10:15',
    ]);

    $this->actingAs($other)->postJson('/api/v1/events/import/csv', ['file' => $file])->assertOk();

    expect(Event::query()->where('user_id', $owner->id)->count())->toBe(0);
    expect(Event::query()->where('user_id', $other->id)->count())->toBe(1);
});
