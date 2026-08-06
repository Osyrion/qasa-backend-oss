<?php

declare(strict_types=1);

use App\Modules\Shared\Presentation\Controllers\NotificationController;
use App\Modules\Shared\Presentation\Controllers\NotificationPreferencesController;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Route;

/**
 * No `feature:` gate and no `permission:` middleware: reading your own
 * notifications is not a plan feature and no ability grants or withholds it
 * — NotificationPolicy checks the only thing that matters, which is whether
 * the row is addressed to you.
 *
 * read-all and preferences are registered before the {notification} routes
 * so the literal segments are not swallowed by the wildcard.
 */
Route::prefix('api/v1')->middleware(['auth:sanctum', 'throttle:api', SubstituteBindings::class])->group(function (): void {
    Route::prefix('notifications')->name('notifications.')->group(function (): void {
        Route::get('/', [NotificationController::class, 'index'])->name('index');
        Route::get('unread-count', [NotificationController::class, 'unreadCount'])->name('unread-count');
        Route::post('read-all', [NotificationController::class, 'markAllRead'])->name('read-all');
        Route::get('preferences', [NotificationPreferencesController::class, 'show'])->name('preferences.show');
        Route::put('preferences', [NotificationPreferencesController::class, 'update'])->name('preferences.update');
        Route::patch('{notification}/read', [NotificationController::class, 'markRead'])->name('read');
        Route::delete('{notification}', [NotificationController::class, 'destroy'])->name('destroy');
    });
});
