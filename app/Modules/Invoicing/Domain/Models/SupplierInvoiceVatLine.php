<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Domain\Models;

use Database\Factories\Modules\Invoicing\Domain\Models\SupplierInvoiceVatLineFactory;
use Eloquent;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $supplier_invoice_id
 * @property numeric $vat_rate
 * @property numeric $base
 * @property numeric $vat_amount
 * @property int $sort_order
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read SupplierInvoice|null $supplierInvoice
 *
 * @method static SupplierInvoiceVatLineFactory factory($count = null, $state = [])
 * @method static Builder<static>|SupplierInvoiceVatLine newModelQuery()
 * @method static Builder<static>|SupplierInvoiceVatLine newQuery()
 * @method static Builder<static>|SupplierInvoiceVatLine query()
 * @method static Builder<static>|SupplierInvoiceVatLine whereBase($value)
 * @method static Builder<static>|SupplierInvoiceVatLine whereCreatedAt($value)
 * @method static Builder<static>|SupplierInvoiceVatLine whereId($value)
 * @method static Builder<static>|SupplierInvoiceVatLine whereSortOrder($value)
 * @method static Builder<static>|SupplierInvoiceVatLine whereSupplierInvoiceId($value)
 * @method static Builder<static>|SupplierInvoiceVatLine whereUpdatedAt($value)
 * @method static Builder<static>|SupplierInvoiceVatLine whereVatAmount($value)
 * @method static Builder<static>|SupplierInvoiceVatLine whereVatRate($value)
 *
 * @mixin Eloquent
 */
class SupplierInvoiceVatLine extends Model
{
    /** @use HasFactory<SupplierInvoiceVatLineFactory> */
    use HasFactory;

    use HasUuids;

    protected $fillable = [
        'supplier_invoice_id',
        'vat_rate',
        'base',
        'vat_amount',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'vat_rate' => 'decimal:2',
            'base' => 'decimal:2',
            'vat_amount' => 'decimal:2',
            'sort_order' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<SupplierInvoice, $this>
     */
    public function supplierInvoice(): BelongsTo
    {
        return $this->belongsTo(SupplierInvoice::class);
    }
}
