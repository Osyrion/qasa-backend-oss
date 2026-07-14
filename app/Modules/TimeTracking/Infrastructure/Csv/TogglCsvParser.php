<?php

declare(strict_types=1);

namespace App\Modules\TimeTracking\Infrastructure\Csv;

use App\Modules\TimeTracking\Application\Contracts\TimeEntryCsvParserInterface;
use Carbon\Carbon;

/**
 * Parse CSV files exported from Toggl
 */
class TogglCsvParser implements TimeEntryCsvParserInterface
{
    public function canHandle(array $headers): bool
    {
        $expectedHeaders = ['User', 'Project', 'Description', 'Start date', 'Start time', 'End date', 'End time'];

        return array_all($expectedHeaders, fn ($header) => in_array($header, $headers));
    }

    /**
     * Parse a Toggl CSV row into a time entry array
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    public function parseRow(array $row): array
    {
        $startDateTime = $this->parseDateTime($row['Start date'] ?? '', $row['Start time'] ?? '');
        $endDateTime = $this->parseDateTime($row['End date'] ?? '', $row['End time'] ?? '');

        $durationSeconds = 0;
        if ($startDateTime && $endDateTime) {
            $durationSeconds = (int) $startDateTime->diffInSeconds($endDateTime);
        }

        return [
            'description' => $row['Description'] ?? '',
            'started_at' => $startDateTime,
            'ended_at' => $endDateTime,
            'duration_seconds' => $durationSeconds,
            'is_billable' => ! isset($row['Billable']) || $row['Billable'] !== 'No',
            'source' => 'toggl',
            'external_id' => md5(implode('|', [$row['Description'] ?? '', $row['Start date'] ?? '', $row['Start time'] ?? ''])),
        ];
    }

    /**
     * Parse date and time strings into a Carbon instance
     */
    private function parseDateTime(?string $date, ?string $time): ?Carbon
    {
        if (! $date || ! $time) {
            return null;
        }

        try {
            return Carbon::createFromFormat('m/d/Y H:i', "{$date} {$time}");
        } catch (\Exception) {
            return null;
        }
    }
}
