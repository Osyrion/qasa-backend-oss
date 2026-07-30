<?php

declare(strict_types=1);

use App\Modules\Invoicing\Domain\Models\SupplierInvoice;
use App\Modules\Invoicing\Domain\Models\SupplierInvoiceVatLine;
use Illuminate\Database\Eloquent\Collection;

it('recalculates header totals as the sum of its vat recap lines', function (): void {
    $invoice = new SupplierInvoice;
    $invoice->setRelation('vatLines', new Collection([
        new SupplierInvoiceVatLine(['vat_rate' => 20, 'base' => 100, 'vat_amount' => 20]),
        new SupplierInvoiceVatLine(['vat_rate' => 10, 'base' => 50, 'vat_amount' => 5]),
    ]));

    $invoice->recalculateTotals();

    expect((float) $invoice->subtotal)->toBe(150.0)
        ->and((float) $invoice->vat_amount)->toBe(25.0)
        ->and((float) $invoice->total)->toBe(175.0);
});

it('recalculates to zero with no vat lines', function (): void {
    $invoice = new SupplierInvoice;
    $invoice->setRelation('vatLines', new Collection);

    $invoice->recalculateTotals();

    expect((float) $invoice->subtotal)->toBe(0.0)
        ->and((float) $invoice->vat_amount)->toBe(0.0)
        ->and((float) $invoice->total)->toBe(0.0);
});
