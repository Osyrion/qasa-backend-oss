<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Application\Contracts;

use App\Modules\Invoicing\Application\DTOs\InvoiceExportData;
use App\Modules\Invoicing\Application\DTOs\SupplierInvoiceExportData;
use App\Modules\Invoicing\Domain\ValueObjects\RenderableInvoice;
use App\Modules\Invoicing\Domain\ValueObjects\RenderableSupplierInvoice;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * Documents in the shape something outside Invoicing can render them.
 *
 * The fourth shape in the read API, and the odd one out. Sums cross when the
 * caller picks the range; rows cross when the set is bounded and the
 * arithmetic cannot be redone in SQL; a summary crosses when a consumer only
 * needs to name a document. A renderer needs none of those — it needs the
 * document, all of it, because printing it is the whole job. See
 * {@see RenderableInvoice} for why that is published as a value anyway rather
 * than excused as a case where the aggregate may travel.
 *
 * The VAT recap is computed here rather than left to the caller. Three
 * exporters used to ask `VatRecapCalculator` themselves, which meant three
 * modules holding a Domain service to reproduce a figure the document could
 * have handed them.
 */
interface RenderableDocuments
{
    /**
     * @throws ModelNotFoundException when the account cannot see the document
     */
    public function invoice(string $invoiceId): RenderableInvoice;

    /**
     * Issued documents for an accountant handoff, in the order the export
     * expects them.
     *
     * A whole period crosses at once, which the row-versus-sum rule would
     * normally refuse — but the caller is writing a file with one entry per
     * document, so there is no aggregate that could stand in for it.
     *
     * @return list<RenderableInvoice>
     */
    public function invoicesForExport(InvoiceExportData $filter): array;

    /**
     * @return list<RenderableSupplierInvoice>
     */
    public function supplierInvoicesForExport(SupplierInvoiceExportData $filter): array;
}
