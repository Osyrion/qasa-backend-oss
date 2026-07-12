<?php

declare(strict_types=1);

namespace App\Modules\Shared\Authorization;

/**
 * Core abilities checked by module policies via $user->can('x.y').
 *
 * In the OSS edition Gate::before grants every ability listed here (data
 * isolation stays with HasUserScope and the policies' account checks); the
 * SaaS edition backs them with spatie/laravel-permission through the Team
 * module's PermissionCatalog.
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
            'timetracking.view',
            'timetracking.manage',
            'invoices.view',
            'invoices.manage',
            'pricing.view',
            'pricing.manage',
            'reports.view',
            'calendar.view',
            'calendar.manage',
            'integrations.manage',
        ];
    }

    public static function handles(string $ability): bool
    {
        return in_array($ability, self::abilities(), true);
    }
}
