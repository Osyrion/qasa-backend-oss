<?php

declare(strict_types=1);

use App\Modules\Invoicing\Presentation\Controllers\AiCredentialController;
use App\Modules\Invoicing\Presentation\Controllers\BankAccountController;
use App\Modules\Invoicing\Presentation\Controllers\CashDocumentController;
use App\Modules\Invoicing\Presentation\Controllers\ClientPortalController;
use App\Modules\Invoicing\Presentation\Controllers\ExchangeRateController;
use App\Modules\Invoicing\Presentation\Controllers\ExpenseAttachmentController;
use App\Modules\Invoicing\Presentation\Controllers\ExpenseController;
use App\Modules\Invoicing\Presentation\Controllers\InvoiceController;
use App\Modules\Invoicing\Presentation\Controllers\InvoiceExportController;
use App\Modules\Invoicing\Presentation\Controllers\InvoiceInboxController;
use App\Modules\Invoicing\Presentation\Controllers\InvoicePaymentController;
use App\Modules\Invoicing\Presentation\Controllers\InvoicePdfController;
use App\Modules\Invoicing\Presentation\Controllers\InvoiceUblController;
use App\Modules\Invoicing\Presentation\Controllers\PublicInvoiceController;
use App\Modules\Invoicing\Presentation\Controllers\PublicQuoteController;
use App\Modules\Invoicing\Presentation\Controllers\QuoteController;
use App\Modules\Invoicing\Presentation\Controllers\RecurringInvoiceTemplateController;
use App\Modules\Invoicing\Presentation\Controllers\StatisticsController;
use App\Modules\Invoicing\Presentation\Controllers\SupplierInvoiceController;
use App\Modules\Invoicing\Presentation\Controllers\VatRateController;
use App\Modules\Invoicing\Presentation\Controllers\VatReportController;
use App\Modules\Invoicing\Presentation\Controllers\WorkReportController;
use App\Modules\Shared\Presentation\Middleware\BindClientPortalTenant;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Route;

Route::prefix('api/v1')->middleware(['auth:sanctum', 'throttle:api', 'residency.required', SubstituteBindings::class])->group(function (): void {

    // CSV stays available on every plan; the accounting-system exports
    // (Pohoda/Omega/ISDOC) are a Pro feature — see routes/accounting.php.
    Route::get('invoices/export/csv', [InvoiceExportController::class, 'csv'])->name('invoices.export.csv');

    // Account-wide financial aggregates, same gate the premium Reports module
    // puts on /reports/* — so the whole prefix answers to one ability instead
    // of half of it being open. Every role in PermissionCatalog::matrix()
    // holds reports.view, so this changes nothing for a team member; what it
    // stops is an API token scoped to something else reading the account's
    // entire financial picture, which no Policy on this path ever asked about.
    Route::middleware('permission:reports.view')->group(function (): void {
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
    });

    Route::apiResource('invoices', InvoiceController::class)
        ->middlewareFor('store', 'idempotent');

    Route::apiResource('bank-accounts', BankAccountController::class)
        ->parameters(['bank-accounts' => 'bank_account']);

    Route::apiResource('vat-rates', VatRateController::class)
        ->parameters(['vat-rates' => 'vat_rate']);

    Route::apiResource('expenses', ExpenseController::class);
    Route::post('expenses/{expense}/attachment', [ExpenseAttachmentController::class, 'store'])
        ->name('expenses.attachment.store');
    Route::get('expenses/{expense}/attachment', [ExpenseAttachmentController::class, 'show'])
        ->name('expenses.attachment.show');
    Route::delete('expenses/{expense}/attachment', [ExpenseAttachmentController::class, 'destroy'])
        ->name('expenses.attachment.destroy');

    Route::apiResource('exchange-rates', ExchangeRateController::class)
        ->only(['index', 'store', 'destroy']);

    Route::apiResource('supplier-invoices', SupplierInvoiceController::class)
        ->parameters(['supplier-invoices' => 'supplier_invoice']);

    Route::post('supplier-invoices/{supplier_invoice}/status', [SupplierInvoiceController::class, 'updateStatus'])
        ->name('supplier-invoices.status');

    Route::get('supplier-invoices/{supplier_invoice}/payment-qr', [SupplierInvoiceController::class, 'paymentQr'])
        ->name('supplier-invoices.payment-qr');

    // Before the resource so "upload" isn't captured as {inbox_item}.
    Route::post('invoice-inbox/upload', [InvoiceInboxController::class, 'upload'])
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

        // `verified` on anything that reaches a third party — see
        // Shared\Support\VerifiedSenderGuard, which is the enforcement point
        // (the scheduler reaches these same actions with no middleware in
        // front of it). Here it only buys the interactive caller a clean 403
        // instead of the guard's 422.
        Route::post('email', [InvoiceController::class, 'email'])
            ->middleware(['throttle:invoice-email', 'verified', 'idempotent'])
            ->name('invoices.email');
        Route::post('remind', [InvoiceController::class, 'remind'])
            ->middleware(['throttle:invoice-email', 'verified'])
            ->name('invoices.remind');
        Route::post('corrective', [InvoiceController::class, 'createCorrective'])->name('invoices.corrective');
        Route::post('settle', [InvoiceController::class, 'settle'])->name('invoices.settle');
        Route::post('items', [InvoiceController::class, 'addItem'])->name('invoices.items.store');
        Route::delete('items/{item}', [InvoiceController::class, 'removeItem'])->name('invoices.items.destroy');
        // Minting the link publishes the document at a guessable-by-nobody but
        // permanent URL, so it is the same "reaches a third party" boundary as
        // e-mailing it. Revoking one is cleanup — never gated.
        Route::post('public-link', [InvoiceController::class, 'createPublicLink'])
            ->middleware('verified')
            ->name('invoices.public-link.store');
        Route::delete('public-link', [InvoiceController::class, 'revokePublicLink'])->name('invoices.public-link.destroy');
        Route::get('payments', [InvoicePaymentController::class, 'index'])->name('invoices.payments.index');
        Route::post('payments', [InvoicePaymentController::class, 'store'])
            ->middleware('idempotent')
            ->name('invoices.payments.store');
        Route::delete('payments/{payment}', [InvoicePaymentController::class, 'destroy'])->name('invoices.payments.destroy');
        Route::get('export/ubl', [InvoiceUblController::class, 'export'])->name('invoices.export.ubl');
        Route::get('pdf/download', [InvoicePdfController::class, 'download'])->name('invoices.pdf.download');
        Route::get('pdf/preview', [InvoicePdfController::class, 'preview'])->name('invoices.pdf.preview');
        Route::get('work-report', [WorkReportController::class, 'index'])->name('invoices.work-report.index');
        Route::put('work-report', [WorkReportController::class, 'update'])->name('invoices.work-report.update');
        Route::post('work-report/generate', [WorkReportController::class, 'generate'])->name('invoices.work-report.generate');
    });

    // Cash receipts and payments (PPD/VPD). No PUT and no DELETE — a cash
    // document is an accounting record, corrected by `reverse`, never edited.
    Route::get('cash-documents', [CashDocumentController::class, 'index'])->name('cash-documents.index');
    Route::post('cash-documents', [CashDocumentController::class, 'store'])
        ->middleware('idempotent')
        ->name('cash-documents.store');
    Route::get('cash-documents/{cashDocument}', [CashDocumentController::class, 'show'])->name('cash-documents.show');
    Route::get('cash-documents/{cashDocument}/pdf', [CashDocumentController::class, 'pdf'])->name('cash-documents.pdf');
    Route::post('cash-documents/{cashDocument}/reverse', [CashDocumentController::class, 'reverse'])->name('cash-documents.reverse');
    Route::get('cash-book', [CashDocumentController::class, 'book'])->name('cash-book.index');

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
        Route::post('generate', [RecurringInvoiceTemplateController::class, 'generate'])
            ->name('recurring-invoice-templates.generate');
    });

    Route::middleware('feature:quotes')->group(function (): void {
        Route::apiResource('quotes', QuoteController::class);

        Route::prefix('quotes/{quote}')->scopeBindings()->group(function (): void {
            Route::post('status', [QuoteController::class, 'updateStatus'])->name('quotes.status');
            Route::post('email', [QuoteController::class, 'email'])
                ->middleware(['throttle:invoice-email', 'verified'])
                ->name('quotes.email');
            Route::post('items', [QuoteController::class, 'addItem'])->name('quotes.items.store');
            Route::delete('items/{item}', [QuoteController::class, 'removeItem'])->name('quotes.items.destroy');
            Route::get('pdf/download', [QuoteController::class, 'pdfDownload'])->name('quotes.pdf.download');
            Route::get('pdf/preview', [QuoteController::class, 'pdfPreview'])->name('quotes.pdf.preview');
            Route::post('public-link', [QuoteController::class, 'createPublicLink'])
                ->middleware('verified')
                ->name('quotes.public-link.store');
            Route::delete('public-link', [QuoteController::class, 'revokePublicLink'])->name('quotes.public-link.destroy');
            Route::post('convert-to-invoice', [QuoteController::class, 'convertToInvoice'])->name('quotes.convert-to-invoice');
            Route::post('convert-to-order', [QuoteController::class, 'convertToOrder'])->name('quotes.convert-to-order');
        });
    });

});

Route::prefix('api/v1/ai-credentials')->middleware(['auth:sanctum', 'throttle:api', SubstituteBindings::class])->group(function (): void {
    Route::get('/', [AiCredentialController::class, 'index'])->name('ai-credentials.index');
    Route::put('{provider}', [AiCredentialController::class, 'upsert'])->name('ai-credentials.upsert');
    Route::delete('{provider}', [AiCredentialController::class, 'destroy'])->name('ai-credentials.destroy');
    Route::post('{provider}/test', [AiCredentialController::class, 'test'])
        ->middleware('throttle:ai-credential-test')
        ->name('ai-credentials.test');
});

Route::prefix('api/v1/public')->middleware(['throttle:public-doc', SubstituteBindings::class])->group(function (): void {
    Route::middleware('public.document:invoices')->group(function (): void {
        Route::get('invoices/{token}', [PublicInvoiceController::class, 'show'])->name('public.invoices.show');
        Route::get('invoices/{token}/pdf', [PublicInvoiceController::class, 'pdf'])->name('public.invoices.pdf');
    });

    Route::middleware('public.document:quotes')->group(function (): void {
        Route::get('quotes/{token}', [PublicQuoteController::class, 'show'])->name('public.quotes.show');
        Route::get('quotes/{token}/pdf', [PublicQuoteController::class, 'pdf'])->name('public.quotes.pdf');
    });
});

Route::prefix('api/v1/public')->middleware(['throttle:public-decide', SubstituteBindings::class])->group(function (): void {
    Route::middleware('public.document:quotes')->group(function (): void {
        Route::post('quotes/{token}/accept', [PublicQuoteController::class, 'accept'])->name('public.quotes.accept');
        Route::post('quotes/{token}/reject', [PublicQuoteController::class, 'reject'])->name('public.quotes.reject');
    });
});

// Client portal — one durable link per client rather than one per document.
// Unauthenticated like the public document pages, and bound the same way:
// BindClientPortalTenant resolves token → account through a SECURITY DEFINER
// function, and the controller narrows further to the one client that holds
// the token (the row-level policy scopes accounts, not clients).
Route::prefix('api/v1/portal')->middleware(['throttle:client-portal', BindClientPortalTenant::class])->group(function (): void {
    Route::get('{token}', [ClientPortalController::class, 'show'])->name('portal.show');
    Route::get('{token}/invoices', [ClientPortalController::class, 'invoices'])->name('portal.invoices.index');
    Route::get('{token}/quotes', [ClientPortalController::class, 'quotes'])->name('portal.quotes.index');
    Route::get('{token}/invoices/{invoice}/pdf', [ClientPortalController::class, 'pdf'])->name('portal.invoices.pdf');
});
