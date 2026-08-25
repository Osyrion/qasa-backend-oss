<?php

declare(strict_types=1);

use App\Modules\Invoicing\Application\Contracts\RenderableDocuments;
use App\Modules\Invoicing\Application\DTOs\SupplierInvoiceExportData;
use App\Modules\Invoicing\Domain\Models\Invoice;
use App\Modules\Invoicing\Domain\ValueObjects\RenderableInvoice;
use App\Modules\Invoicing\Domain\ValueObjects\RenderableSupplierInvoice;

/**
 * The accounting exporters take values, not models, so their tests have to go
 * through the same mapping production does. Going through the real contract
 * rather than hand-building a value is the point: a field the mapping forgets
 * shows up as a wrong export here, which is where these tests can see it.
 *
 * @param  iterable<Invoice>  $invoices
 * @return list<RenderableInvoice>
 */
function renderableInvoices(iterable $invoices): array
{
    $documents = app(RenderableDocuments::class);
    $renderables = [];

    foreach ($invoices as $invoice) {
        // One at a time, in the order given: the golden exports pin a document
        // order the export filter does not reproduce.
        $renderables[] = $documents->invoice($invoice->id);
    }

    return $renderables;
}

/**
 * Every received document the account has, through the export path itself —
 * there is no by-id read for one, because nothing in production wants one.
 *
 * @return list<RenderableSupplierInvoice>
 */
function renderableSupplierInvoices(string $from = '2000-01-01', string $to = '2100-12-31'): array
{
    return app(RenderableDocuments::class)->supplierInvoicesForExport(
        SupplierInvoiceExportData::from(['date_from' => $from, 'date_to' => $to]),
    );
}
