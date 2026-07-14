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

it('mirrors VAT into self_assessed_vat_amount and excludes it from total for eu_reverse_charge', function (): void {
    $invoice = new SupplierInvoice(['vat_regime' => 'eu_reverse_charge']);
    $invoice->setRelation('vatLines', new Collection([
        new SupplierInvoiceVatLine(['vat_rate' => 23, 'base' => 1000, 'vat_amount' => 230]),
    ]));

    $invoice->recalculateTotals();

    expect((float) $invoice->subtotal)->toBe(1000.0)
        ->and((float) $invoice->vat_amount)->toBe(230.0)
        ->and((float) $invoice->self_assessed_vat_amount)->toBe(230.0)
        ->and((float) $invoice->total)->toBe(1000.0);
});

it('mirrors VAT into self_assessed_vat_amount and excludes it from total for import', function (): void {
    $invoice = new SupplierInvoice(['vat_regime' => 'import']);
    $invoice->setRelation('vatLines', new Collection([
        new SupplierInvoiceVatLine(['vat_rate' => 23, 'base' => 1000, 'vat_amount' => 230]),
    ]));

    $invoice->recalculateTotals();

    expect((float) $invoice->total)->toBe(1000.0)
        ->and((float) $invoice->self_assessed_vat_amount)->toBe(230.0);
});

it('leaves the domestic regime unchanged with self_assessed_vat_amount at zero', function (): void {
    $invoice = new SupplierInvoice(['vat_regime' => 'domestic']);
    $invoice->setRelation('vatLines', new Collection([
        new SupplierInvoiceVatLine(['vat_rate' => 23, 'base' => 1000, 'vat_amount' => 230]),
    ]));

    $invoice->recalculateTotals();

    expect((float) $invoice->total)->toBe(1230.0)
        ->and((float) $invoice->self_assessed_vat_amount)->toBe(0.0);
});
