<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Domain\Enums;

enum RateLevel: string
{
    case User = 'user';
    case Client = 'client';
    case Order = 'order';

    public function label(): string
    {
        return match ($this) {
            self::User => 'Globálna sadzba',
            self::Client => 'Sadzba klienta',
            self::Order => 'Sadzba zákazky',
        };
    }

    /**
     * Higher specificity wins during rate resolution.
     */
    public function specificity(): int
    {
        return match ($this) {
            self::User => 1,
            self::Client => 2,
            self::Order => 3,
        };
    }

    /**
     * All known level values for validation.
     *
     * @return list<string>
     */
    public static function knownValues(): array
    {
        return array_column(self::cases(), 'value');
    }
}
