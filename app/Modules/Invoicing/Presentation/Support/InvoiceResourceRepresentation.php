<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Presentation\Support;

use App\Modules\Invoicing\Application\Contracts\InvoiceRepresentation;
use App\Modules\Invoicing\Domain\Models\Invoice;
use App\Modules\Invoicing\Presentation\Resources\InvoiceResource;

/**
 * Renders the invoice through the resource Invoicing's own endpoints use, so a
 * document handed back by an MCP tool and one fetched directly cannot say
 * different things.
 */
final readonly class InvoiceResourceRepresentation implements InvoiceRepresentation
{
    public function forInvoice(string $invoiceId): array
    {
        /** @var Invoice $invoice */
        $invoice = Invoice::query()->with(['client', 'items'])->findOrFail($invoiceId);

        return InvoiceResource::make($invoice)->resolve();
    }
}
