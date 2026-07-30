<?php

declare(strict_types=1);

namespace App\Modules\Shared\Enums;

enum Currency: string
{
    case CZK = 'CZK';
    case EUR = 'EUR';
    case USD = 'USD';

    public function label(): string
    {
        return match ($this) {
            self::CZK => 'Česká koruna',
            self::EUR => 'Euro',
            self::USD => 'US Dollar',
        };
    }

    public function symbol(): string
    {
        return match ($this) {
            self::CZK => 'Kč',
            self::EUR => '€',
            self::USD => '$',
        };
    }

    public function isEurozone(): bool
    {
        return $this === self::EUR;
    }

    public function isCzech(): bool
    {
        return $this === self::CZK;
    }

    public function isAmerican(): bool
    {
        return $this === self::USD;
    }

    /**
     * All known currency values for validation.
     *
     * @return list<string>
     */
    public static function knownValues(): array
    {
        return array_column(self::cases(), 'value');
    }
}
