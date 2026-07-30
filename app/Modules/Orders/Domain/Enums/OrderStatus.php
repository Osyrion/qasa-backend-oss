<?php

declare(strict_types=1);

namespace App\Modules\Orders\Domain\Enums;

enum OrderStatus: string
{
    case Active = 'active';
    case Paused = 'paused';
    case Completed = 'completed';
    case Archived = 'archived';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Aktívna',
            self::Paused => 'Pozastavená',
            self::Completed => 'Dokončená',
            self::Archived => 'Archivovaná',
        };
    }

    public function isEditable(): bool
    {
        return match ($this) {
            self::Active, self::Paused => true,
            default => false,
        };
    }

    public function isBillable(): bool
    {
        return match ($this) {
            self::Active, self::Completed => true,
            default => false,
        };
    }
}
