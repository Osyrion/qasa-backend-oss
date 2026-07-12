<?php

declare(strict_types=1);

use App\Modules\Calendar\Presentation\Controllers\EventController;
use App\Modules\Calendar\Presentation\Controllers\EventExportController;
use App\Modules\Calendar\Presentation\Controllers\EventImportController;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Route;

Route::prefix('api/v1')->middleware(['auth:sanctum', SubstituteBindings::class])->group(function (): void {

    Route::post('events/import/csv', [EventImportController::class, 'csv'])->name('events.import.csv');
    Route::post('events/import/ics', [EventImportController::class, 'ics'])->name('events.import.ics');
    Route::get('events/export/csv', [EventExportController::class, 'csv'])->name('events.export.csv');
    Route::get('events/export/ics', [EventExportController::class, 'ics'])->name('events.export.ics');

    Route::apiResource('events', EventController::class);

});
