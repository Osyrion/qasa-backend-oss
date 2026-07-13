<?php

declare(strict_types=1);

use App\Modules\Pricing\Presentation\Controllers\PriceListController;
use App\Modules\Pricing\Presentation\Controllers\PriceListItemController;
use App\Modules\Pricing\Presentation\Controllers\RateController;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Route;

Route::prefix('api/v1')->middleware(['auth:sanctum', SubstituteBindings::class])->group(function (): void {
    Route::get('rates/effective', [RateController::class, 'effective'])->name('rates.effective');
    Route::get('rates', [RateController::class, 'index'])->name('rates.index');
    Route::post('rates', [RateController::class, 'store'])->name('rates.store');
    Route::delete('rates/{rate}', [RateController::class, 'destroy'])->name('rates.destroy');

    Route::apiResource('price-lists', PriceListController::class)->parameters(['price-lists' => 'price_list']);

    Route::prefix('price-lists/{priceList}')->group(function (): void {
        Route::get('items', [PriceListItemController::class, 'index'])->name('price-lists.items.index');
        Route::post('items', [PriceListItemController::class, 'store'])->name('price-lists.items.store');
        Route::put('items/{item}', [PriceListItemController::class, 'update'])->name('price-lists.items.update');
        Route::delete('items/{item}', [PriceListItemController::class, 'destroy'])->name('price-lists.items.destroy');
    });
});
