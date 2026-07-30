<?php

declare(strict_types=1);

use App\Modules\Taxation\Presentation\Controllers\ContributionController;
use App\Modules\Taxation\Presentation\Controllers\ResidencyController;
use App\Modules\Taxation\Presentation\Controllers\TaxReturnController;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Route;

Route::prefix('api/v1')->middleware(['auth:sanctum', 'throttle:api', 'verified', SubstituteBindings::class])->group(function (): void {
    Route::post('auth/complete-residency', [ResidencyController::class, 'complete'])->name('auth.complete-residency');
    Route::get('auth/registry-lookup', [ResidencyController::class, 'lookup'])
        ->middleware('throttle:registry-lookup')
        ->name('auth.registry-lookup');

    Route::get('contributions/summary', [ContributionController::class, 'summary']);
    Route::apiResource('contributions', ContributionController::class)
        ->parameters(['contributions' => 'contribution']);

    Route::prefix('tax-return')->middleware(['residency.required'])->group(function (): void {
        Route::middleware('feature:tax_return')->group(function (): void {
            Route::get('schema', [TaxReturnController::class, 'schema']);
            Route::get('system-data', [TaxReturnController::class, 'systemData']);
            Route::put('draft', [TaxReturnController::class, 'saveDraft']);
            Route::get('draft', [TaxReturnController::class, 'getDraft']);
            Route::post('preview', [TaxReturnController::class, 'preview'])->middleware('throttle:tax-return-preview');
        });

        // Discarding a draft stays available even after a downgrade —
        // it's cleanup, not a premium capability.
        Route::delete('draft', [TaxReturnController::class, 'deleteDraft']);
    });
});
