<?php

declare(strict_types=1);

namespace App\Modules\Calendar\Domain\Enums;

enum EventSource: string
{
    case Manual = 'manual';
    case CsvImport = 'csv_import';
    case IcsImport = 'ics_import';
}
