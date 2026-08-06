<?php

declare(strict_types=1);

namespace App\Modules\Shared\Enums;

enum NotificationSeverity: string
{
    case Info = 'info';
    case Success = 'success';
    case Warning = 'warning';
    case Error = 'error';
}
