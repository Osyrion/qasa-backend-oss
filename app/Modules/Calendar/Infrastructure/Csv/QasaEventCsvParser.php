<?php

declare(strict_types=1);

namespace App\Modules\Calendar\Infrastructure\Csv;

use App\Modules\Calendar\Application\Contracts\EventCsvParserInterface;
use App\Modules\Calendar\Domain\Enums\EventSource;

/**
 * Parses the QASA canonical event export/import CSV format:
 * `title;description;location;color;is_all_day;starts_at;ends_at`.
 */
final class QasaEventCsvParser implements EventCsvParserInterface
{
    public function canHandle(array $headers): bool
    {
        $required = ['title', 'starts_at', 'ends_at'];

        return array_all($required, fn (string $header): bool => in_array($header, $headers, true));
    }

    public function parseRow(array $row): array
    {
        $title = $row['title'] ?? '';
        $startsAt = $row['starts_at'] ?? '';
        $endsAt = $row['ends_at'] ?? '';

        return [
            'title' => $title,
            'description' => $this->nullable($row['description'] ?? null),
            'location' => $this->nullable($row['location'] ?? null),
            'color' => $this->nullable($row['color'] ?? null),
            'is_all_day' => in_array($row['is_all_day'] ?? '0', ['1', 'true', 'yes'], true),
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'source' => EventSource::CsvImport,
            'external_uid' => md5(implode('|', [$title, $startsAt, $endsAt])),
        ];
    }

    private function nullable(?string $value): ?string
    {
        return $value === null || $value === '' ? null : $value;
    }
}
