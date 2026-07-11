<?php

declare(strict_types=1);

namespace App\Modules\Calendar\Application\Contracts;

interface EventCsvParserInterface
{
    /**
     * @param  array<int, string>  $headers
     */
    public function canHandle(array $headers): bool;

    /**
     * @param  array<string, string>  $row
     * @return array<string, mixed>
     */
    public function parseRow(array $row): array;
}
