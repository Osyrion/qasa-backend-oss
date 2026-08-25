<?php

declare(strict_types=1);

namespace App\Modules\Clients\Domain\Enums;

/**
 * Which side of a trade a client is on.
 *
 * Not exclusive — the same company can be both, which is why these are two
 * boolean columns rather than one type. Named as an enum because the plan
 * limits are counted per role and a caller has to be able to say which one it
 * means without knowing the column.
 */
enum ClientRole: string
{
    case Customer = 'customer';
    case Vendor = 'vendor';

    /** The `clients` column that carries this role. */
    public function column(): string
    {
        return match ($this) {
            self::Customer => 'is_customer',
            self::Vendor => 'is_vendor',
        };
    }

    /** The plan limit that caps how many of this role an account may use. */
    public function planLimitKey(): string
    {
        return match ($this) {
            self::Customer => 'max_customers',
            self::Vendor => 'max_vendors',
        };
    }
}
