<?php

declare(strict_types=1);

use App\Modules\Invoicing\Presentation\Controllers\BankAccountController;
use App\Modules\Invoicing\Presentation\Controllers\ExchangeRateController;
use App\Modules\Invoicing\Presentation\Controllers\ExpenseAttachmentController;
use App\Modules\Invoicing\Presentation\Controllers\ExpenseController;
use App\Modules\Invoicing\Presentation\Controllers\InvoiceController;
use App\Modules\Invoicing\Presentation\Controllers\InvoiceExportController;
use App\Modules\Invoicing\Presentation\Controllers\InvoiceInboxController;
use App\Modules\Invoicing\Presentation\Controllers\InvoicePaymentController;
use App\Modules\Invoicing\Presentation\Controllers\InvoicePdfController;
use App\Modules\Invoicing\Presentation\Controllers\PublicInvoiceController;
use App\Modules\Invoicing\Presentation\Controllers\PublicQuoteController;
use App\Modules\Invoicing\Presentation\Controllers\QuoteController;
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
    Route::get('invoices/export/omega', [InvoiceExportController::class, 'omega'])->name('invoices.export.omega');
    Route::get('supplier-invoices/export/omega', [InvoiceExportController::class, 'supplierOmega'])->name('supplier-invoices.export.omega');
    Route::get('invoices/{invoice}/export/isdoc', [InvoiceExportController::class, 'isdoc'])->name('invoices.export.isdoc');

    Route::get('reports/eu-sales-list', [VatReportController::class, 'euSalesList'])->name('reports.eu-sales-list');
    Route::get('reports/vat-control-statement', [VatReportController::class, 'vatControlStatement'])->name('reports.vat-control-statement');
    Route::get('reports/vat-control-statement/xml', [VatReportController::class, 'vatControlStatementXml'])->name('reports.vat-control-statement.xml');

    Route::prefix('statistics')->name('statistics.')->group(function (): void {
        Route::get('overview', [StatisticsController::class, 'overview'])->name('overview');
        Route::get('receivables', [StatisticsController::class, 'receivables'])->name('receivables');
        Route::get('partners', [StatisticsController::class, 'partners'])->name('partners');
        Route::get('health', [StatisticsController::class, 'health'])->name('health');
        Route::get('tables', [StatisticsController::class, 'tables'])->name('tables');
    });

    Route::apiResource('invoices', InvoiceController::class)
        ->middlewareFor('store', 'idempotent');

    Route::apiResource('bank-accounts', BankAccountController::class)
        ->parameters(['bank-accounts' => 'bank_account']);

    Route::apiResource('expenses', ExpenseController::class);

    Route::post('expenses/{expense}/attachment', [ExpenseAttachmentController::class, 'store'])
        ->name('expenses.attachment.store');
    Route::get('expenses/{expense}/attachment', [ExpenseAttachmentController::class, 'show'])
        ->name('expenses.attachment.show');
    Route::delete('expenses/{expense}/attachment', [ExpenseAttachmentController::class, 'destroy'])
        ->name('expenses.attachment.destroy');

    Route::apiResource('exchange-rates', ExchangeRateController::class)
        ->only(['index', 'store', 'destroy']);

    Route::apiResource('vat-rates', VatRateController::class)
        ->parameters(['vat-rates' => 'vat_rate']);

    Route::apiResource('supplier-invoices', SupplierInvoiceController::class)
        ->parameters(['supplier-invoices' => 'supplier_invoice']);

    Route::post('supplier-invoices/{supplier_invoice}/status', [SupplierInvoiceController::class, 'updateStatus'])
        ->name('supplier-invoices.status');

    Route::post('supplier-invoices/{supplier_invoice}/verify-account', [SupplierInvoiceController::class, 'verifyAccount'])
        ->name('supplier-invoices.verify-account');
    Route::get('supplier-invoices/{supplier_invoice}/payment-qr', [SupplierInvoiceController::class, 'paymentQr'])
        ->name('supplier-invoices.payment-qr');

    // Before the resource so "upload" isn't captured as {inbox_item}.
    Route::post('invoice-inbox/upload', [InvoiceInboxController::class, 'upload'])
        ->middleware('throttle:30,1')
        ->name('invoice-inbox.upload');

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
            ->middleware(['throttle:invoice-email', 'idempotent'])
            ->name('invoices.email');
        Route::post('remind', [InvoiceController::class, 'remind'])
            ->middleware('throttle:invoice-email')
            ->name('invoices.remind');
        Route::post('corrective', [InvoiceController::class, 'createCorrective'])->name('invoices.corrective');
        Route::post('settle', [InvoiceController::class, 'settle'])->name('invoices.settle');
        Route::post('items', [InvoiceController::class, 'addItem'])->name('invoices.items.store');
        Route::delete('items/{item}', [InvoiceController::class, 'removeItem'])->name('invoices.items.destroy');
        Route::post('public-link', [InvoiceController::class, 'createPublicLink'])->name('invoices.public-link.store');
        Route::delete('public-link', [InvoiceController::class, 'revokePublicLink'])->name('invoices.public-link.destroy');
        Route::get('payments', [InvoicePaymentController::class, 'index'])->name('invoices.payments.index');
        Route::post('payments', [InvoicePaymentController::class, 'store'])
            ->middleware('idempotent')
            ->name('invoices.payments.store');
        Route::delete('payments/{payment}', [InvoicePaymentController::class, 'destroy'])->name('invoices.payments.destroy');
        Route::get('pdf/download', [InvoicePdfController::class, 'download'])->name('invoices.pdf.download');
        Route::get('pdf/preview', [InvoicePdfController::class, 'preview'])->name('invoices.pdf.preview');
        Route::get('work-report', [WorkReportController::class, 'index'])->name('invoices.work-report.index');
        Route::put('work-report', [WorkReportController::class, 'update'])->name('invoices.work-report.update');
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

    Route::apiResource('quotes', QuoteController::class);

    Route::prefix('quotes/{quote}')->scopeBindings()->group(function (): void {
        Route::post('items', [QuoteController::class, 'addItem'])->name('quotes.items.store');
        Route::delete('items/{item}', [QuoteController::class, 'removeItem'])->name('quotes.items.destroy');
        Route::post('status', [QuoteController::class, 'updateStatus'])->name('quotes.status');
        Route::post('email', [QuoteController::class, 'email'])
            ->middleware('throttle:invoice-email')
            ->name('quotes.email');
        Route::post('public-link', [QuoteController::class, 'createPublicLink'])->name('quotes.public-link.store');
        Route::delete('public-link', [QuoteController::class, 'revokePublicLink'])->name('quotes.public-link.destroy');
        Route::get('pdf/download', [QuoteController::class, 'pdfDownload'])->name('quotes.pdf.download');
        Route::get('pdf/preview', [QuoteController::class, 'pdfPreview'])->name('quotes.pdf.preview');
        Route::post('convert-to-invoice', [QuoteController::class, 'convertToInvoice'])->name('quotes.convert-to-invoice');
        Route::post('convert-to-order', [QuoteController::class, 'convertToOrder'])->name('quotes.convert-to-order');
    });

});

// Unauthenticated client-facing document pages — no auth:sanctum, IP-throttled.
Route::prefix('api/v1/public')->middleware('throttle:public-doc')->group(function (): void {
    Route::get('invoices/{token}', [PublicInvoiceController::class, 'show'])->name('public.invoices.show');
    Route::get('invoices/{token}/pdf', [PublicInvoiceController::class, 'pdf'])->name('public.invoices.pdf');

    Route::get('quotes/{token}', [PublicQuoteController::class, 'show'])->name('public.quotes.show');
    Route::get('quotes/{token}/pdf', [PublicQuoteController::class, 'pdf'])->name('public.quotes.pdf');
    Route::post('quotes/{token}/accept', [PublicQuoteController::class, 'accept'])
        ->middleware('throttle:public-decide')
        ->name('public.quotes.accept');
    Route::post('quotes/{token}/reject', [PublicQuoteController::class, 'reject'])
        ->middleware('throttle:public-decide')
        ->name('public.quotes.reject');
});
