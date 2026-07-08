<?php

declare(strict_types=1);

use App\Modules\TimeTracking\Presentation\Controllers\ClockifySyncController;
use App\Modules\TimeTracking\Presentation\Controllers\ExchangeRateController;
use App\Modules\TimeTracking\Presentation\Controllers\ExpenseController;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Route;

Route::prefix('api/v1')->middleware(['auth:sanctum', SubstituteBindings::class])->group(function (): void {

    Route::post('time-entries/sync/clockify', [ClockifySyncController::class, 'sync'])
        ->name('time-entries.sync.clockify');

    Route::apiResource('expenses', ExpenseController::class);

    Route::apiResource('exchange-rates', ExchangeRateController::class)
        ->only(['index', 'store', 'destroy']);

});
