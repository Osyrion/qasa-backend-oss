<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Domain\Enums;

enum RecurringTemplateStatus: string
{
    case Active = 'active';
    case Paused = 'paused';
    case Expired = 'expired';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Aktivní',
            self::Paused => 'Pozastavená',
            self::Expired => 'Vypršelá',
        };
    }

    public function isActive(): bool
    {
        return $this === self::Active;
    }
}
