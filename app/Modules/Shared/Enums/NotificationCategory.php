<?php

declare(strict_types=1);

namespace App\Modules\Shared\Enums;

/**
 * Coarse grouping the front end filters and icons by, so it never has to
 * know the individual notification classes. Deliberately short — a category
 * per module would just be the module list again.
 */
enum NotificationCategory: string
{
    case Invoice = 'invoice';
    case Quote = 'quote';
    case Tax = 'tax';
    case Billing = 'billing';
    case Banking = 'banking';
    case System = 'system';
}
