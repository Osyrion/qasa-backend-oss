<?php

declare(strict_types=1);

namespace App\Modules\Taxation\Domain\Enums;

enum TaxFilingStatus: string
{
    case Generated = 'generated';
    case Filed = 'filed';
    case Superseded = 'superseded';
}
