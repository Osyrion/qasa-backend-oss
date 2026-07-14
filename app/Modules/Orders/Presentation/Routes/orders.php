<?php

declare(strict_types=1);

use App\Modules\Orders\Presentation\Controllers\OrderAttachmentController;
use App\Modules\Orders\Presentation\Controllers\OrderController;
use App\Modules\Orders\Presentation\Controllers\OrderItemController;
use App\Modules\Orders\Presentation\Controllers\OrderNoteController;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Route;

Route::prefix('api/v1')->middleware(['auth:sanctum', SubstituteBindings::class])->group(function (): void {

    Route::apiResource('orders', OrderController::class);

    Route::prefix('orders/{order}')->scopeBindings()->group(function (): void {

        Route::get('items', [OrderItemController::class, 'index'])->name('orders.items.index');
        Route::post('items', [OrderItemController::class, 'store'])->name('orders.items.store');
        Route::put('items/{item}', [OrderItemController::class, 'update'])->name('orders.items.update');
        Route::delete('items/{item}', [OrderItemController::class, 'destroy'])->name('orders.items.destroy');

        Route::get('notes', [OrderNoteController::class, 'index'])->name('orders.notes.index');
        Route::post('notes', [OrderNoteController::class, 'store'])->name('orders.notes.store');
        Route::delete('notes/{note}', [OrderNoteController::class, 'destroy'])->name('orders.notes.destroy');

        Route::get('attachments', [OrderAttachmentController::class, 'index'])->name('orders.attachments.index');
        Route::post('attachments', [OrderAttachmentController::class, 'store'])->name('orders.attachments.store');
        Route::delete('attachments/{attachment}', [OrderAttachmentController::class, 'destroy'])->name('orders.attachments.destroy');

    });

});
