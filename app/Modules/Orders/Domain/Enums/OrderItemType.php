<?php

declare(strict_types=1);

namespace App\Modules\Orders\Domain\Enums;

enum OrderItemType: string
{
    case Service = 'service';
    case Product = 'product';
    case Time = 'time';

    public function label(): string
    {
        return match ($this) {
            self::Service => 'Úkon / služba',
            self::Product => 'Tovar / materiál',
            self::Time => 'Čas',
        };
    }
}
