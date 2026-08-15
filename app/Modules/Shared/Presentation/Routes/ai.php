<?php

declare(strict_types=1);

use App\Modules\Shared\Presentation\Controllers\AiTransparencyController;
use Illuminate\Support\Facades\Route;

/**
 * No `permission:` gate and no `feature:` gate on purpose: this is the
 * transparency notice itself (AI Act art. 50), so every team member must be
 * able to read it — including on a plan where no AI capability is available,
 * where it correctly reports everything as disabled.
 */
Route::prefix('api/v1')->middleware(['auth:sanctum', 'throttle:api'])->group(function (): void {
    Route::get('ai/transparency', [AiTransparencyController::class, 'show'])->name('ai.transparency');
});
