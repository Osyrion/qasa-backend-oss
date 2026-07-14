<?php

declare(strict_types=1);

namespace App\Modules\Shared\Authorization;

/**
 * Core abilities checked by module policies via $user->can('x.y').
 *
 * Gate::before grants every ability listed here (data isolation stays with
 * HasUserScope and the policies' account checks). Kept as a single catalog
 * so token scopes and policies share one authoritative list.
 */
final class AbilityCatalog
{
    /**
     * @return list<string>
     */
    public static function abilities(): array
    {
        return [
            'clients.view',
            'clients.manage',
            'orders.view',
            'orders.manage',
            'invoices.view',
            'invoices.manage',
            'expenses.view',
            'expenses.manage',
            'reports.view',
            'activity.view',
        ];
    }

    public static function handles(string $ability): bool
    {
        return in_array($ability, self::abilities(), true);
    }
}
