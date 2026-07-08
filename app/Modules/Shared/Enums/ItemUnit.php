<?php

declare(strict_types=1);

namespace App\Modules\Shared\Enums;

enum ItemUnit: string
{
    case Piece = 'ks';
    case Hour = 'hod';
    case Day = 'deň';
    case Month = 'mesiac';
    case Km = 'km';
    case Litre = 'l';
    case Dl = 'dl';
    case Ml = 'ml';
    case Kg = 'kg';
    case Gram = 'g';
    case Metre = 'm';
    case M2 = 'm2';
    case M3 = 'm3';

    public function label(): string
    {
        return match ($this) {
            self::Piece => 'kus',
            self::Hour => 'hodina',
            self::Day => 'deň',
            self::Month => 'mesiac',
            self::Km => 'kilometer',
            self::Litre => 'liter',
            self::Dl => 'deciliter',
            self::Ml => 'mililiter',
            self::Kg => 'kilogram',
            self::Gram => 'gram',
            self::Metre => 'meter',
            self::M2 => 'meter štvorcový',
            self::M3 => 'meter kubický',
        };
    }

    public function isTimeBased(): bool
    {
        return match ($this) {
            self::Hour, self::Day, self::Month => true,
            default => false,
        };
    }

    /**
     * Try to resolve from string, return null if it's a custom unit.
     */
    public static function tryFromCustom(string $value): self|string
    {
        return self::tryFrom($value) ?? $value;
    }

    /**
     * All known unit values for validation.
     */
    public static function knownValues(): array
    {
        return array_column(self::cases(), 'value');
    }
}
