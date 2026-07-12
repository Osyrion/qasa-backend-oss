<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Domain\Models;

use App\Modules\Pricing\Domain\Models\PriceListItem;
use App\Modules\Shared\Enums\ItemUnit;
use Database\Factories\Modules\Invoicing\Domain\Models\QuoteItemFactory;
use Eloquent;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $quote_id
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
 * @property-read Quote|null $quote
 * @property-read PriceListItem|null $priceListItem
 *
 * @method static QuoteItemFactory factory($count = null, $state = [])
 * @method static Builder<static>|QuoteItem newModelQuery()
 * @method static Builder<static>|QuoteItem newQuery()
 * @method static Builder<static>|QuoteItem query()
 *
 * @mixin Eloquent
 */
class QuoteItem extends Model
{
    use HasFactory;
    use HasUuids;

    protected $fillable = [
        'quote_id',
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

    public function quote(): BelongsTo
    {
        return $this->belongsTo(Quote::class);
    }

    public function priceListItem(): BelongsTo
    {
        return $this->belongsTo(PriceListItem::class);
    }
}
