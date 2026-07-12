<?php

declare(strict_types=1);

use App\Modules\Invoicing\Presentation\Controllers\BankAccountController;
use App\Modules\Invoicing\Presentation\Controllers\InvoiceController;
use App\Modules\Invoicing\Presentation\Controllers\InvoiceExportController;
use App\Modules\Invoicing\Presentation\Controllers\InvoiceInboxController;
use App\Modules\Invoicing\Presentation\Controllers\InvoicePaymentController;
use App\Modules\Invoicing\Presentation\Controllers\InvoicePdfController;
use App\Modules\Invoicing\Presentation\Controllers\RecurringInvoiceTemplateController;
use App\Modules\Invoicing\Presentation\Controllers\StatisticsController;
use App\Modules\Invoicing\Presentation\Controllers\SupplierInvoiceController;
use App\Modules\Invoicing\Presentation\Controllers\VatRateController;
use App\Modules\Invoicing\Presentation\Controllers\VatReportController;
use App\Modules\Invoicing\Presentation\Controllers\WorkReportController;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Route;

Route::prefix('api/v1')->middleware(['auth:sanctum', SubstituteBindings::class])->group(function (): void {

    Route::get('invoices/export/pohoda', [InvoiceExportController::class, 'pohoda'])->name('invoices.export.pohoda');
    Route::get('invoices/export/csv', [InvoiceExportController::class, 'csv'])->name('invoices.export.csv');

    Route::get('reports/eu-sales-list', [VatReportController::class, 'euSalesList'])->name('reports.eu-sales-list');

    Route::prefix('statistics')->name('statistics.')->group(function (): void {
        Route::get('overview', [StatisticsController::class, 'overview'])->name('overview');
        Route::get('receivables', [StatisticsController::class, 'receivables'])->name('receivables');
        Route::get('partners', [StatisticsController::class, 'partners'])->name('partners');
        Route::get('health', [StatisticsController::class, 'health'])->name('health');
        Route::get('tables', [StatisticsController::class, 'tables'])->name('tables');
    });

    Route::apiResource('invoices', InvoiceController::class);

    Route::apiResource('bank-accounts', BankAccountController::class)
        ->parameters(['bank-accounts' => 'bank_account']);

    Route::apiResource('vat-rates', VatRateController::class)
        ->parameters(['vat-rates' => 'vat_rate']);

    Route::apiResource('supplier-invoices', SupplierInvoiceController::class)
        ->parameters(['supplier-invoices' => 'supplier_invoice']);

    Route::post('supplier-invoices/{supplier_invoice}/status', [SupplierInvoiceController::class, 'updateStatus'])
        ->name('supplier-invoices.status');

    Route::apiResource('invoice-inbox', InvoiceInboxController::class)
        ->parameters(['invoice-inbox' => 'inbox_item'])
        ->only(['index', 'show', 'destroy']);

    Route::get('invoice-inbox/{inbox_item}/download', [InvoiceInboxController::class, 'download'])
        ->name('invoice-inbox.download');
    Route::post('invoice-inbox/{inbox_item}/convert', [InvoiceInboxController::class, 'convert'])
        ->name('invoice-inbox.convert');
    Route::post('invoice-inbox/{inbox_item}/ignore', [InvoiceInboxController::class, 'ignore'])
        ->name('invoice-inbox.ignore');

    Route::prefix('invoices/{invoice}')->scopeBindings()->group(function (): void {
        Route::post('status', [InvoiceController::class, 'updateStatus'])->name('invoices.status');
        Route::post('email', [InvoiceController::class, 'email'])
            ->middleware('throttle:invoice-email')
            ->name('invoices.email');
        Route::post('remind', [InvoiceController::class, 'remind'])
            ->middleware('throttle:invoice-email')
            ->name('invoices.remind');
        Route::post('corrective', [InvoiceController::class, 'createCorrective'])->name('invoices.corrective');
        Route::post('items', [InvoiceController::class, 'addItem'])->name('invoices.items.store');
        Route::delete('items/{item}', [InvoiceController::class, 'removeItem'])->name('invoices.items.destroy');
        Route::post('payments', [InvoicePaymentController::class, 'store'])->name('invoices.payments.store');
        Route::delete('payments/{payment}', [InvoicePaymentController::class, 'destroy'])->name('invoices.payments.destroy');
        Route::get('pdf/download', [InvoicePdfController::class, 'download'])->name('invoices.pdf.download');
        Route::get('pdf/preview', [InvoicePdfController::class, 'preview'])->name('invoices.pdf.preview');
        Route::get('work-report', [WorkReportController::class, 'index'])->name('invoices.work-report.index');
        Route::put('work-report', [WorkReportController::class, 'update'])->name('invoices.work-report.update');
        Route::post('work-report/generate', [WorkReportController::class, 'generate'])->name('invoices.work-report.generate');
    });

    // Generate invoice from order
    Route::post('invoices/generate-from-order', [InvoiceController::class, 'generateFromOrder'])
        ->name('invoices.generate-from-order');

    Route::apiResource('recurring-invoice-templates', RecurringInvoiceTemplateController::class)
        ->parameters(['recurring-invoice-templates' => 'template']);

    Route::prefix('recurring-invoice-templates/{template}')->group(function (): void {
        Route::post('pause', [RecurringInvoiceTemplateController::class, 'pause'])
            ->name('recurring-invoice-templates.pause');
        Route::post('resume', [RecurringInvoiceTemplateController::class, 'resume'])
            ->name('recurring-invoice-templates.resume');
    });

});
