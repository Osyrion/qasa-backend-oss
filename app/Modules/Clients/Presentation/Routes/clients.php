<?php

declare(strict_types=1);

use App\Modules\Clients\Presentation\Controllers\ClientController;
use App\Modules\Clients\Presentation\Controllers\CompanyLookupController;
use App\Modules\Clients\Presentation\Controllers\ContactPersonController;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Route;

Route::prefix('api/v1')->middleware(['auth:sanctum', SubstituteBindings::class])->group(function (): void {
    // Registered before the apiResource so they are not captured by clients/{client}.
    Route::get('clients/lookup', [CompanyLookupController::class, 'lookup'])->name('clients.lookup');
    Route::get('clients/verify-vat', [CompanyLookupController::class, 'verifyVat'])->name('clients.verify-vat');

    Route::apiResource('clients', ClientController::class);

    Route::prefix('clients/{client}')->group(function (): void {
        Route::get('contact-persons', [ContactPersonController::class, 'index'])->name('clients.contact-persons.index');
        Route::post('contact-persons', [ContactPersonController::class, 'store'])->name('clients.contact-persons.store');
        Route::put('contact-persons/{contactPerson}', [ContactPersonController::class, 'update'])->name('clients.contact-persons.update');
        Route::delete('contact-persons/{contactPerson}', [ContactPersonController::class, 'destroy'])->name('clients.contact-persons.destroy');
    });
});
