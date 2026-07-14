<?php

declare(strict_types=1);

namespace App\Modules\TimeTracking\Infrastructure\Csv;

use App\Modules\TimeTracking\Application\Contracts\TimeEntryCsvParserInterface;
use Carbon\Carbon;

/**
 * Parse CSV files exported from Clockify
 */
class ClockifyCsvParser implements TimeEntryCsvParserInterface
{
    public function canHandle(array $headers): bool
    {
        $expectedHeaders = ['Email', 'Project', 'Task', 'Description', 'Start Date', 'Start Time', 'End Date', 'End Time'];

        return array_all($expectedHeaders, fn ($header) => in_array($header, $headers));
    }

    /**
     * Parse a Clockify CSV row into a time entry array
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    public function parseRow(array $row): array
    {
        $startDateTime = $this->parseDateTime($row['Start Date'] ?? '', $row['Start Time'] ?? '');
        $endDateTime = $this->parseDateTime($row['End Date'] ?? '', $row['End Time'] ?? '');

        $durationSeconds = 0;
        if ($startDateTime && $endDateTime) {
            $durationSeconds = (int) $startDateTime->diffInSeconds($endDateTime);
        }

        $description = trim(implode(' - ', array_filter([
            $row['Task'] ?? '',
            $row['Description'] ?? '',
        ])));

        return [
            'description' => $description ?: 'Time Entry',
            'started_at' => $startDateTime,
            'ended_at' => $endDateTime,
            'duration_seconds' => $durationSeconds,
            'is_billable' => true,
            'source' => 'clockify',
            'external_id' => md5(implode('|', [$description, $row['Start Date'] ?? '', $row['Start Time'] ?? ''])),
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
