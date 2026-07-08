<?php

declare(strict_types=1);

namespace App\Modules\TimeTracking\Domain\Enums;

enum ExchangeRateSource: string
{
    case Manual = 'manual';
    case Ecb = 'ecb';
    case Fixer = 'fixer';
    case Cnb = 'cnb';

    public function label(): string
    {
        return match ($this) {
            self::Manual => 'Manuálny',
            self::Ecb => 'ECB',
            self::Fixer => 'Fixer',
            self::Cnb => 'ČNB',
        };
    }
}
