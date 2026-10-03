<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Domain\Models;

use App\Modules\Orders\Domain\Models\OrderItem;
use App\Modules\Shared\Enums\ItemUnit;
use App\Modules\Shared\Traits\CalculatesLineTotals;
use Database\Factories\Modules\Invoicing\Domain\Models\InvoiceItemFactory;
use Eloquent;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $invoice_id
 * @property string|null $order_item_id
 * @property string|null $time_entry_id
 * @property string|null $price_list_item_id
 * @property string $description
 * @property numeric $quantity
 * @property string $unit
 * @property numeric $unit_price Excl. VAT
 * @property numeric $vat_rate
 * @property numeric $vat_amount
 * @property numeric $total_excl_vat
 * @property numeric $total_incl_vat
 * @property int $sort_order
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read ItemUnit|string $unit_enum
 * @property-read Invoice|null $invoice
 * @property-read OrderItem|null $orderItem
 *
 * @method static InvoiceItemFactory factory($count = null, $state = [])
 * @method static Builder<static> newModelQuery()
 * @method static Builder<static> newQuery()
 * @method static Builder<static> query()
 * @method static Builder<static> whereCreatedAt($value)
 * @method static Builder<static> whereDescription($value)
 * @method static Builder<static> whereId($value)
 * @method static Builder<static> whereInvoiceId($value)
 * @method static Builder<static> whereOrderItemId($value)
 * @method static Builder<static> whereQuantity($value)
 * @method static Builder<static> whereSortOrder($value)
 * @method static Builder<static> whereTimeEntryId($value)
 * @method static Builder<static> whereTotalExclVat($value)
 * @method static Builder<static> whereTotalInclVat($value)
 * @method static Builder<static> whereUnit($value)
 * @method static Builder<static> whereUnitPrice($value)
 * @method static Builder<static> whereUpdatedAt($value)
 * @method static Builder<static> whereVatAmount($value)
 * @method static Builder<static> whereVatRate($value)
 *
 * @mixin Eloquent
 */
class InvoiceItem extends Model
{
    use CalculatesLineTotals;

    /** @use HasFactory<InvoiceItemFactory> */
    use HasFactory;

    use HasUuids;

    protected $fillable = [
        'invoice_id',
        'order_item_id',
        'time_entry_id',
        'price_list_item_id',
        'description',
        'quantity',
        'unit',
        'unit_price',
        'vat_rate',
        'vat_amount',
        'total_excl_vat',
        'total_incl_vat',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:3',
            'unit_price' => 'decimal:2',
            'vat_rate' => 'decimal:2',
            'vat_amount' => 'decimal:2',
            'total_excl_vat' => 'decimal:2',
            'total_incl_vat' => 'decimal:2',
            'sort_order' => 'integer',
        ];
    }

    // ── Computed ──────────────────────────────────────────────────────────────

    public function getUnitEnumAttribute(): ItemUnit|string
    {
        return ItemUnit::tryFromCustom($this->unit);
    }

    public function hasVat(): bool
    {
        return (float) $this->vat_rate > 0;
    }

    public function isFromOrderItem(): bool
    {
        return $this->order_item_id !== null;
    }

    public function isFromTimeEntry(): bool
    {
        return $this->time_entry_id !== null;
    }

    public function isManual(): bool
    {
        return $this->order_item_id === null && $this->time_entry_id === null;
    }

    // ── Relations ─────────────────────────────────────────────────────────────

    /**
     * @return BelongsTo<Invoice, $this>
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /**
     * @return BelongsTo<OrderItem, $this>
     */
    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }
}
