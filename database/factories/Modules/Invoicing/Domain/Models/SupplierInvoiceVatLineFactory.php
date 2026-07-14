<?php

declare(strict_types=1);

namespace Database\Factories\Modules\Invoicing\Domain\Models;

use App\Modules\Invoicing\Domain\Models\SupplierInvoiceVatLine;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SupplierInvoiceVatLine>
 */
class SupplierInvoiceVatLineFactory extends Factory
{
    protected $model = SupplierInvoiceVatLine::class;

    public function definition(): array
    {
        $base = fake()->randomFloat(2, 50, 5000);
        $vatRate = fake()->randomElement([0, 10, 20, 21, 23]);

        return [
            'supplier_invoice_id' => SupplierInvoiceFactory::new(),
            'vat_rate' => $vatRate,
            'base' => $base,
            'vat_amount' => round($base * $vatRate / 100, 2),
            'sort_order' => 0,
        ];
    }
}
