<?php

declare(strict_types=1);

namespace App\Modules\Orders\Domain\Models;

use App\Modules\Shared\Enums\ItemUnit;
use App\Modules\TimeTracking\Domain\Models\TimeEntry;
use Database\Factories\Modules\Orders\Domain\Models\OrderItemFactory;
use Eloquent;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $order_id
 * @property string $type service=úkon, product=tovar/materiál, time=čas z time entry
 * @property string $description
 * @property numeric $quantity
 * @property string $unit ks|hod|deň|mesiac|km|l|dl|ml|kg|g|m|m2|m3 or custom
 * @property numeric $unit_price Excl. VAT
 * @property numeric $vat_rate e.g. 0, 10, 20, 21, 23
 * @property numeric $vat_amount Computed: quantity * unit_price * vat_rate / 100
 * @property numeric $total_excl_vat quantity * unit_price
 * @property numeric $total_incl_vat total_excl_vat + vat_amount
 * @property int $sort_order
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read ItemUnit|string $unit_enum
 * @property-read Order|null $order
 * @property-read TimeEntry|null $timeEntry
 *
 * @method static OrderItemFactory factory($count = null, $state = [])
 * @method static Builder<static>|OrderItem newModelQuery()
 * @method static Builder<static>|OrderItem newQuery()
 * @method static Builder<static>|OrderItem query()
 * @method static Builder<static>|OrderItem whereCreatedAt($value)
 * @method static Builder<static>|OrderItem whereDescription($value)
 * @method static Builder<static>|OrderItem whereId($value)
 * @method static Builder<static>|OrderItem whereOrderId($value)
 * @method static Builder<static>|OrderItem whereQuantity($value)
 * @method static Builder<static>|OrderItem whereSortOrder($value)
 * @method static Builder<static>|OrderItem whereTotalExclVat($value)
 * @method static Builder<static>|OrderItem whereTotalInclVat($value)
 * @method static Builder<static>|OrderItem whereType($value)
 * @method static Builder<static>|OrderItem whereUnit($value)
 * @method static Builder<static>|OrderItem whereUnitPrice($value)
 * @method static Builder<static>|OrderItem whereUpdatedAt($value)
 * @method static Builder<static>|OrderItem whereVatAmount($value)
 * @method static Builder<static>|OrderItem whereVatRate($value)
 *
 * @mixin Eloquent
 */
class OrderItem extends Model
{
    use HasFactory;
    use HasUuids;

    protected $fillable = [
        'order_id',
        'price_list_item_id',
        'type',
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

    public function isService(): bool
    {
        return $this->type === 'service';
    }

    public function isProduct(): bool
    {
        return $this->type === 'product';
    }

    public function isTime(): bool
    {
        return $this->type === 'time';
    }

    public function hasVat(): bool
    {
        return (float) $this->vat_rate > 0;
    }

    /**
     * Recalculate and set all derived price fields.
     * Call before save when quantity, unit_price or vat_rate changes.
     */
    public function recalculate(): self
    {
        $excl = round((float) $this->quantity * (float) $this->unit_price, 2);
        $vat = round($excl * (float) $this->vat_rate / 100, 2);

        $this->total_excl_vat = $excl;
        $this->vat_amount = $vat;
        $this->total_incl_vat = $excl + $vat;

        return $this;
    }

    // ── Relations ─────────────────────────────────────────────────────────────

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function timeEntry(): HasOne
    {
        return $this->hasOne(TimeEntry::class);
    }
}
