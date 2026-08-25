<?php

declare(strict_types=1);

namespace App\Modules\Shared\Presentation\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Writes the handful of operational numbers only the application knows into a
 * Prometheus textfile, for the node_exporter `textfile` collector to pick up
 * (docker/alloy/config.alloy, docs/plans/GRAFANA_OBSERVABILITY_PLAN.md).
 *
 * Why a file and not an HTTP endpoint: a /metrics route would have to be
 * routable, authenticated, excluded from the tenant middleware stack and then
 * kept out of the public API surface — four decisions, each a chance to leak.
 * A file written by the scheduler and read by a sidecar crosses no trust
 * boundary at all, needs no route, and keeps working while PHP-FPM is the
 * thing that is broken.
 *
 * The metric that matters most is the cheapest one:
 * `flok_metrics_last_export_timestamp_seconds`. It is written on every run, so
 * an alert on its staleness is an alert on **the scheduler being dead** — the
 * failure that silently stops invoice reminders, dunning, bank sync and every
 * retention purge, and that nothing else in this repository would notice.
 *
 * Reads only: `jobs` and `failed_jobs` are framework tables with no owning
 * account and no RLS policy (see VerifyRowLevelSecurityCommand), so this runs
 * without binding a tenant and sees the whole queue, which is the point.
 */
class ExportOperationalMetricsCommand extends Command
{
    protected $signature = 'qasa:metrics:export
        {--path= : Directory to write the .prom file into, instead of the configured one}';

    protected $description = 'Export queue and scheduler health as a Prometheus textfile for the monitoring agent';

    /**
     * The node_exporter textfile collector reads whatever it finds mid-write,
     * so the file is never written in place — it is written beside itself and
     * renamed, which is atomic within a filesystem.
     */
    private const FILENAME = 'flok.prom';

    public function handle(): int
    {
        /** @var string|null $override */
        $override = $this->option('path');

        $directory = $override ?? (string) config('qasa.metrics.textfile_dir');

        try {
            $path = $this->write($directory, $this->render());
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("Metrics written to {$path}");

        return self::SUCCESS;
    }

    private function render(): string
    {
        $lines = [];

        $lines[] = '# HELP flok_build_info Deployment identity, always 1.';
        $lines[] = '# TYPE flok_build_info gauge';
        $lines[] = sprintf(
            'flok_build_info{environment="%s",edition="%s"} 1',
            $this->escape((string) config('app.env')),
            $this->escape((string) config('qasa.edition')),
        );

        $lines = array_merge($lines, $this->queueMetrics(), $this->failedJobMetrics());

        $lines[] = '# HELP flok_metrics_last_export_timestamp_seconds When this file was last written. Stale means the scheduler is not running.';
        $lines[] = '# TYPE flok_metrics_last_export_timestamp_seconds gauge';
        $lines[] = 'flok_metrics_last_export_timestamp_seconds '.time();

        return implode("\n", $lines)."\n";
    }

    /**
     * @return list<string>
     */
    private function queueMetrics(): array
    {
        $now = time();

        /** @var list<object{queue: string, pending: int, reserved: int, oldest_available_at: int|null}> $rows */
        $rows = DB::table('jobs')
            ->selectRaw('queue')
            ->selectRaw('count(*) filter (where reserved_at is null) as pending')
            ->selectRaw('count(*) filter (where reserved_at is not null) as reserved')
            ->selectRaw('min(available_at) filter (where reserved_at is null and available_at <= ?) as oldest_available_at', [$now])
            ->groupBy('queue')
            ->get()
            ->all();

        $lines = [
            '# HELP flok_queue_pending_jobs Jobs waiting to be picked up.',
            '# TYPE flok_queue_pending_jobs gauge',
        ];

        $reserved = [
            '# HELP flok_queue_reserved_jobs Jobs a worker currently holds.',
            '# TYPE flok_queue_reserved_jobs gauge',
        ];

        $age = [
            '# HELP flok_queue_oldest_pending_seconds Age of the oldest job that is due and still unclaimed.',
            '# TYPE flok_queue_oldest_pending_seconds gauge',
        ];

        // An empty `jobs` table is the healthy state and reports nothing at
        // all, which would leave the alert below with no series to evaluate.
        // The default queue is therefore always emitted, at zero.
        $seen = [];

        foreach ($rows as $row) {
            $queue = $this->escape($row->queue);
            $seen[] = $row->queue;

            $lines[] = sprintf('flok_queue_pending_jobs{queue="%s"} %d', $queue, $row->pending);
            $reserved[] = sprintf('flok_queue_reserved_jobs{queue="%s"} %d', $queue, $row->reserved);
            // No max(0, ...) here on purpose. The `available_at <= $now`
            // filter in the query is what decides whether a job counts as
            // overdue, and it uses this same $now — so a clamp could only
            // ever restate that rule in a second place, where it would go on
            // hiding a broken filter for as long as anyone cared to look.
            $age[] = sprintf(
                'flok_queue_oldest_pending_seconds{queue="%s"} %d',
                $queue,
                $row->oldest_available_at === null ? 0 : $now - $row->oldest_available_at,
            );
        }

        $default = (string) config('queue.connections.'.config('queue.default').'.queue', 'default');

        if (! in_array($default, $seen, true)) {
            $lines[] = sprintf('flok_queue_pending_jobs{queue="%s"} 0', $this->escape($default));
            $reserved[] = sprintf('flok_queue_reserved_jobs{queue="%s"} 0', $this->escape($default));
            $age[] = sprintf('flok_queue_oldest_pending_seconds{queue="%s"} 0', $this->escape($default));
        }

        return array_merge($lines, $reserved, $age);
    }

    /**
     * @return list<string>
     */
    private function failedJobMetrics(): array
    {
        $total = DB::table('failed_jobs')->count();
        $recent = DB::table('failed_jobs')
            ->where('failed_at', '>=', now()->subHour())
            ->count();

        return [
            '# HELP flok_queue_failed_jobs Rows in failed_jobs. Labelled by window because the table is never pruned automatically.',
            '# TYPE flok_queue_failed_jobs gauge',
            sprintf('flok_queue_failed_jobs{window="all"} %d', $total),
            sprintf('flok_queue_failed_jobs{window="1h"} %d', $recent),
        ];
    }

    private function write(string $directory, string $contents): string
    {
        if (! is_dir($directory) && ! @mkdir($directory, 0775, true) && ! is_dir($directory)) {
            throw new RuntimeException("Cannot create metrics directory: {$directory}");
        }

        $path = rtrim($directory, '/').'/'.self::FILENAME;
        $temporary = $path.'.'.getmypid().'.tmp';

        if (file_put_contents($temporary, $contents) === false) {
            throw new RuntimeException("Cannot write metrics file: {$temporary}");
        }

        // The collector only reads *.prom, so the temporary name above is
        // invisible to it until this line makes it the real file in one step.
        if (! rename($temporary, $path)) {
            @unlink($temporary);

            throw new RuntimeException("Cannot publish metrics file: {$path}");
        }

        return $path;
    }

    /**
     * Prometheus label values escape backslash, double quote and newline —
     * nothing else. Queue names are ours, but a metrics file that can be
     * broken by a string from the database is a parsing outage waiting for
     * the one deployment that renames a queue.
     */
    private function escape(string $value): string
    {
        return str_replace(['\\', '"', "\n"], ['\\\\', '\\"', '\\n'], $value);
    }
}
