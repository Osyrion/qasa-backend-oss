<?php

declare(strict_types=1);

use App\Modules\Taxation\Presentation\Controllers\ContributionController;
use App\Modules\Taxation\Presentation\Controllers\ResidencyController;
use App\Modules\Taxation\Presentation\Controllers\TaxFilingController;
use App\Modules\Taxation\Presentation\Controllers\TaxReturnController;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Route;

Route::prefix('api/v1')->middleware(['auth:sanctum', 'throttle:api', 'verified', SubstituteBindings::class])->group(function (): void {
    Route::post('auth/complete-residency', [ResidencyController::class, 'complete'])->name('auth.complete-residency');
    Route::get('auth/registry-lookup', [ResidencyController::class, 'lookup'])
        ->middleware('throttle:registry-lookup')
        ->name('auth.registry-lookup');

    Route::get('contributions/summary', [ContributionController::class, 'summary'])->name('contributions.summary');
    Route::apiResource('contributions', ContributionController::class)
        ->parameters(['contributions' => 'contribution']);

    // taxation.* like the filing archive and the contribution records below.
    // The draft is keyed by the caller's own user id, so this was never a
    // cross-tenant hole — it is the read-only half of the role matrix, and
    // an API token's own scope, that had no say here.
    Route::prefix('tax-return')->middleware(['residency.required'])->group(function (): void {
        Route::middleware('feature:tax_return')->group(function (): void {
            Route::middleware('permission:taxation.view')->group(function (): void {
                Route::get('schema', [TaxReturnController::class, 'schema'])->name('tax-return.schema');
                Route::get('system-data', [TaxReturnController::class, 'systemData'])->name('tax-return.system-data');
                Route::get('draft', [TaxReturnController::class, 'getDraft'])->name('tax-return.draft.show');
                Route::post('preview', [TaxReturnController::class, 'preview'])
                    ->middleware('throttle:tax-return-preview')
                    ->name('tax-return.preview');
            });

            Route::put('draft', [TaxReturnController::class, 'saveDraft'])
                ->middleware('permission:taxation.manage')
                ->name('tax-return.draft.update');
        });

        // Discarding a draft stays available even after a downgrade —
        // it's cleanup, not a premium capability.
        Route::delete('draft', [TaxReturnController::class, 'deleteDraft'])
            ->middleware('permission:taxation.manage')
            ->name('tax-return.draft.destroy');
    });

    // The filing archive itself is core (SK_VAT_FILING_EDANE_PLAN.md's own
    // edition-boundary decision) — no feature: gate, unlike tax-return
    // above. residency.required since generation needs TaxSystemResolver.
    Route::prefix('tax-filings')->middleware(['residency.required'])->group(function (): void {
        Route::get('', [TaxFilingController::class, 'index'])->name('tax-filings.index');
        Route::post('', [TaxFilingController::class, 'store'])->name('tax-filings.store');
        Route::get('{tax_filing}', [TaxFilingController::class, 'show'])->name('tax-filings.show');
        Route::get('{tax_filing}/recap.pdf', [TaxFilingController::class, 'recap'])->name('tax-filings.recap');
        Route::post('{tax_filing}/mark-filed', [TaxFilingController::class, 'markFiled'])->name('tax-filings.mark-filed');
    });
});
