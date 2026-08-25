<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/**
 * The metrics textfile is read by a process that is not this application and
 * cannot ask it questions — a malformed file is not an error anyone sees, it
 * is a monitoring gap that looks like silence. So the format is asserted, not
 * just the values.
 */
beforeEach(function (): void {
    $this->metricsDir = storage_path('framework/testing/metrics-'.bin2hex(random_bytes(4)));
});

afterEach(function (): void {
    File::deleteDirectory($this->metricsDir);
});

function exportedMetrics(string $directory): string
{
    Artisan::call('qasa:metrics:export', ['--path' => $directory]);

    return (string) file_get_contents($directory.'/flok.prom');
}

it('writes a parseable file, creating the directory', function (): void {
    expect(is_dir($this->metricsDir))->toBeFalse();

    $contents = exportedMetrics($this->metricsDir);

    // Every sample must be preceded by its HELP and TYPE, which is what the
    // node_exporter textfile collector rejects a file for.
    foreach (['flok_build_info', 'flok_queue_pending_jobs', 'flok_queue_failed_jobs', 'flok_metrics_last_export_timestamp_seconds'] as $metric) {
        expect($contents)->toContain("# HELP {$metric} ")
            ->and($contents)->toContain("# TYPE {$metric} gauge");
    }

    expect($contents)->toEndWith("\n");
});

it('counts pending, reserved and overdue jobs per queue', function (): void {
    $now = time();

    DB::table('jobs')->insert([
        // Due 10 minutes ago and still unclaimed — the one that matters.
        ['queue' => 'default', 'payload' => '{}', 'attempts' => 0, 'reserved_at' => null, 'available_at' => $now - 600, 'created_at' => $now - 600],
        ['queue' => 'default', 'payload' => '{}', 'attempts' => 0, 'reserved_at' => null, 'available_at' => $now - 60, 'created_at' => $now - 60],
        // Held by a worker: pending must not count it.
        ['queue' => 'default', 'payload' => '{}', 'attempts' => 1, 'reserved_at' => $now, 'available_at' => $now - 30, 'created_at' => $now - 30],
        // A different queue gets its own series.
        ['queue' => 'ocr', 'payload' => '{}', 'attempts' => 0, 'reserved_at' => null, 'available_at' => $now - 5, 'created_at' => $now - 5],
    ]);

    $contents = exportedMetrics($this->metricsDir);

    expect($contents)->toContain('flok_queue_pending_jobs{queue="default"} 2')
        ->and($contents)->toContain('flok_queue_reserved_jobs{queue="default"} 1')
        ->and($contents)->toContain('flok_queue_pending_jobs{queue="ocr"} 1');

    // The alert this feeds fires above 900s, so the age has to be the oldest
    // due job (~600s), never the newest and never an average.
    preg_match('/flok_queue_oldest_pending_seconds\{queue="default"\} (\d+)/', $contents, $matches);
    expect((int) $matches[1])->toBeGreaterThanOrEqual(600)->toBeLessThan(660);
});

it('reports a zero-age default queue when nothing is waiting', function (): void {
    // An empty table reporting no series at all would leave the "queue not
    // draining" rule with nothing to evaluate, which reads as healthy for the
    // same reason a deleted alert does.
    $contents = exportedMetrics($this->metricsDir);

    expect($contents)->toContain('flok_queue_pending_jobs{queue="default"} 0')
        ->and($contents)->toContain('flok_queue_oldest_pending_seconds{queue="default"} 0');
});

it('does not age a job that is scheduled for the future', function (): void {
    $now = time();

    DB::table('jobs')->insert([
        ['queue' => 'default', 'payload' => '{}', 'attempts' => 0, 'reserved_at' => null, 'available_at' => $now + 3600, 'created_at' => $now],
    ]);

    // A delayed job is waiting on purpose. Counting it as overdue would page
    // someone every time a reminder is scheduled for tomorrow.
    expect(exportedMetrics($this->metricsDir))
        ->toContain('flok_queue_oldest_pending_seconds{queue="default"} 0')
        ->toContain('flok_queue_pending_jobs{queue="default"} 1');
});

it('splits failed jobs into the last hour and all time', function (): void {
    DB::table('failed_jobs')->insert([
        ['uuid' => (string) Str::uuid(), 'connection' => 'database', 'queue' => 'default', 'payload' => '{}', 'exception' => 'x', 'failed_at' => now()->subMinutes(10)],
        ['uuid' => (string) Str::uuid(), 'connection' => 'database', 'queue' => 'default', 'payload' => '{}', 'exception' => 'x', 'failed_at' => now()->subDays(3)],
    ]);

    $contents = exportedMetrics($this->metricsDir);

    expect($contents)->toContain('flok_queue_failed_jobs{window="all"} 2')
        ->and($contents)->toContain('flok_queue_failed_jobs{window="1h"} 1');
});

it('stamps the export time so a dead scheduler is detectable', function (): void {
    $contents = exportedMetrics($this->metricsDir);

    preg_match('/flok_metrics_last_export_timestamp_seconds (\d+)/', $contents, $matches);

    expect((int) $matches[1])->toBeGreaterThanOrEqual(time() - 5);
});

it('escapes label values so a queue name cannot break the file', function (): void {
    DB::table('jobs')->insert([
        ['queue' => 'we"ird\\name', 'payload' => '{}', 'attempts' => 0, 'reserved_at' => null, 'available_at' => time(), 'created_at' => time()],
    ]);

    expect(exportedMetrics($this->metricsDir))
        ->toContain('flok_queue_pending_jobs{queue="we\\"ird\\\\name"} 1');
});

it('leaves no partial file behind', function (): void {
    exportedMetrics($this->metricsDir);

    // The collector reads *.prom on its own schedule, so the real file must
    // appear complete or not at all — hence write-then-rename, and hence no
    // leftover temporary next to it.
    expect(File::files($this->metricsDir))->toHaveCount(1);
});
