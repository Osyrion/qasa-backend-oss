<?php

declare(strict_types=1);

use App\Modules\Shared\Presentation\Controllers\ActivityController;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Route;

Route::prefix('api/v1')->middleware(['auth:sanctum', 'throttle:api', SubstituteBindings::class])->group(function (): void {
    Route::get('activity', [ActivityController::class, 'index'])->name('activity.index');
});
