<?php

declare(strict_types=1);

namespace App\Modules\Shared\Domain\Contracts;

use App\Modules\Shared\Enums\NotificationCategory;

/**
 * A recipient that can decline a category of in-app notification.
 *
 * Implemented by the edition's User model. Notifications are dispatched to a
 * `$notifiable` typed as object by Laravel, so this is what InAppNotification
 * narrows to before asking — the alternative was naming the User model in the
 * shared kernel, which is the dependency the Account contract exists to avoid.
 */
interface ProvidesNotificationPreferences
{
    public function wantsNotificationCategory(NotificationCategory $category): bool;
}
