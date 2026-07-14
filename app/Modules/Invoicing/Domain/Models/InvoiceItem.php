<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Domain\Models;

use App\Modules\Orders\Domain\Models\OrderItem;
use App\Modules\Shared\Enums\ItemUnit;
use App\Modules\Shared\Support\Decimal;
use App\Modules\TimeTracking\Domain\Models\TimeEntry;
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
 * @property-read TimeEntry|null $timeEntry
 *
 * @method static InvoiceItemFactory factory($count = null, $state = [])
 * @method static Builder<static>|InvoiceItem newModelQuery()
 * @method static Builder<static>|InvoiceItem newQuery()
 * @method static Builder<static>|InvoiceItem query()
 * @method static Builder<static>|InvoiceItem whereCreatedAt($value)
 * @method static Builder<static>|InvoiceItem whereDescription($value)
 * @method static Builder<static>|InvoiceItem whereId($value)
 * @method static Builder<static>|InvoiceItem whereInvoiceId($value)
 * @method static Builder<static>|InvoiceItem whereOrderItemId($value)
 * @method static Builder<static>|InvoiceItem whereQuantity($value)
 * @method static Builder<static>|InvoiceItem whereSortOrder($value)
 * @method static Builder<static>|InvoiceItem whereTimeEntryId($value)
 * @method static Builder<static>|InvoiceItem whereTotalExclVat($value)
 * @method static Builder<static>|InvoiceItem whereTotalInclVat($value)
 * @method static Builder<static>|InvoiceItem whereUnit($value)
 * @method static Builder<static>|InvoiceItem whereUnitPrice($value)
 * @method static Builder<static>|InvoiceItem whereUpdatedAt($value)
 * @method static Builder<static>|InvoiceItem whereVatAmount($value)
 * @method static Builder<static>|InvoiceItem whereVatRate($value)
 * @method static Builder<static>|InvoiceItem wherePriceListItemId($value)
 *
 * @mixin Eloquent
 */
class InvoiceItem extends Model
{
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

    public function recalculate(): self
    {
        $excl = Decimal::mul((string) $this->quantity, (string) $this->unit_price);
        $vat = Decimal::mul($excl, Decimal::div((string) $this->vat_rate, '100', 10));

        $this->total_excl_vat = (float) $excl;
        $this->vat_amount = (float) $vat;
        $this->total_incl_vat = (float) Decimal::add($excl, $vat);

        return $this;
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

    /**
     * @return BelongsTo<TimeEntry, $this>
     */
    public function timeEntry(): BelongsTo
    {
        return $this->belongsTo(TimeEntry::class);
    }
}
