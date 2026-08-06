<?php

declare(strict_types=1);

namespace App\Modules\Shared\Enums;

/**
 * How money actually moved.
 *
 * The values are exactly what invoice_payments.method already held as a free
 * string, so this types an existing concept rather than introducing one — no
 * data migration, and the `in:` list that used to live in PaymentData stops
 * being a second place to remember.
 */
enum PaymentMethod: string
{
    case BankTransfer = 'bank_transfer';
    case Cash = 'cash';
    case Card = 'card';
    case Other = 'other';
}
