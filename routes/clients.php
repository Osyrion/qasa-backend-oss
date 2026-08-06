<?php

declare(strict_types=1);

use App\Modules\Clients\Presentation\Controllers\ClientController;
use App\Modules\Clients\Presentation\Controllers\CompanyLookupController;
use App\Modules\Clients\Presentation\Controllers\ContactPersonController;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Route;

Route::prefix('api/v1')->middleware(['auth:sanctum', 'throttle:api', SubstituteBindings::class])->group(function (): void {
    // Registered before the apiResource so they are not captured by clients/{client}.
    Route::get('clients/lookup', [CompanyLookupController::class, 'lookup'])->name('clients.lookup');
    Route::get('clients/verify-vat', [CompanyLookupController::class, 'verifyVat'])->name('clients.verify-vat');

    Route::apiResource('clients', ClientController::class);

    // No scopeBindings() here, unlike the orders/invoices/quotes groups:
    // Laravel derives the relation from the parameter name, and {contactPerson}
    // pluralises to contactPeople() while the relation is contactPersons().
    // ContactPersonController scopes the child to the client in the path
    // explicitly instead — same as PriceListItemController does for its items.
    Route::prefix('clients/{client}')->group(function (): void {
        // Publishes a durable URL the client is meant to open — same
        // third-party boundary as e-mailing a document. Revoking is cleanup.
        Route::post('portal-link', [ClientController::class, 'createPortalLink'])
            ->middleware('verified')
            ->name('clients.portal-link.store');
        Route::delete('portal-link', [ClientController::class, 'revokePortalLink'])->name('clients.portal-link.destroy');
        Route::post('archive', [ClientController::class, 'archive'])->name('clients.archive');
        Route::post('restore', [ClientController::class, 'restore'])->name('clients.restore');

        Route::get('contact-persons', [ContactPersonController::class, 'index'])->name('clients.contact-persons.index');
        Route::post('contact-persons', [ContactPersonController::class, 'store'])->name('clients.contact-persons.store');
        Route::put('contact-persons/{contactPerson}', [ContactPersonController::class, 'update'])->name('clients.contact-persons.update');
        Route::delete('contact-persons/{contactPerson}', [ContactPersonController::class, 'destroy'])->name('clients.contact-persons.destroy');
    });
});
