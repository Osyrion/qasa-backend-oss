<?php

declare(strict_types=1);

use App\Modules\TimeTracking\Presentation\Controllers\ClockifySyncController;
use App\Modules\TimeTracking\Presentation\Controllers\ExchangeRateController;
use App\Modules\TimeTracking\Presentation\Controllers\ExpenseAttachmentController;
use App\Modules\TimeTracking\Presentation\Controllers\ExpenseController;
use App\Modules\TimeTracking\Presentation\Controllers\TimeEntryController;
use App\Modules\TimeTracking\Presentation\Controllers\TimeEntryImportController;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Route;

Route::prefix('api/v1')->middleware(['auth:sanctum', SubstituteBindings::class])->group(function (): void {

    Route::post('time-entries/sync/clockify', [ClockifySyncController::class, 'sync'])
        ->name('time-entries.sync.clockify');

    Route::post('time-entries/import/csv', [TimeEntryImportController::class, 'csv'])
        ->name('time-entries.import.csv');

    Route::post('time-entries/start', [TimeEntryController::class, 'start'])
        ->name('time-entries.start');

    Route::post('time-entries/{time_entry}/stop', [TimeEntryController::class, 'stop'])
        ->name('time-entries.stop');

    Route::apiResource('time-entries', TimeEntryController::class)
        ->parameters(['time-entries' => 'time_entry']);

    Route::apiResource('expenses', ExpenseController::class);

    Route::post('expenses/{expense}/attachment', [ExpenseAttachmentController::class, 'store'])
        ->name('expenses.attachment.store');
    Route::get('expenses/{expense}/attachment', [ExpenseAttachmentController::class, 'show'])
        ->name('expenses.attachment.show');
    Route::delete('expenses/{expense}/attachment', [ExpenseAttachmentController::class, 'destroy'])
        ->name('expenses.attachment.destroy');

    Route::apiResource('exchange-rates', ExchangeRateController::class)
        ->only(['index', 'store', 'destroy']);

});
