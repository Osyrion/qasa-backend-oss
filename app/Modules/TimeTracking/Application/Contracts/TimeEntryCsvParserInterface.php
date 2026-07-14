<?php

declare(strict_types=1);

namespace App\Modules\TimeTracking\Application\Contracts;

interface TimeEntryCsvParserInterface
{
    /**
     * Check if this parser can handle the CSV file.
     *
     * @param  list<string>  $headers
     */
    public function canHandle(array $headers): bool;

    /**
     * Parse a CSV row into a time entry array.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    public function parseRow(array $row): array;
}
