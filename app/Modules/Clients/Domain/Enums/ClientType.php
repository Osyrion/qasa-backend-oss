<?php

declare(strict_types=1);

namespace App\Modules\Clients\Domain\Enums;

enum ClientType: string
{
    case Individual = 'individual';
    case SelfEmployed = 'self_employed';
    case Company = 'company';

    public function label(): string
    {
        return match ($this) {
            self::Individual => 'Fyzická osoba',
            self::SelfEmployed => 'SZČO',
            self::Company => 'Firma',
        };
    }

    public function canHaveContactPersons(): bool
    {
        return $this === self::Company;
    }

    public function requiresCompanyName(): bool
    {
        return $this === self::Company;
    }

    public function requiresPersonName(): bool
    {
        return match ($this) {
            self::Individual, self::SelfEmployed => true,
            default => false,
        };
    }
}
