<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Domain\Models;

use App\Modules\Shared\Enums\ItemUnit;
use Database\Factories\Modules\Pricing\Domain\Models\PriceListItemFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Catalog entry of a price list. Order/invoice items only reference it as
 * provenance — values are always snapshotted at item creation, so later
 * catalog edits never touch existing documents.
 *
 * @property string $id
 * @property string $price_list_id
 * @property string $name
 * @property string|null $description
 * @property string $unit ItemUnit value or custom free-text unit
 * @property numeric $unit_price Excl. VAT
 * @property numeric $vat_rate
 * @property bool $is_active
 * @property int $sort_order
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read ItemUnit|string $unit_enum
 * @property-read PriceList|null $priceList
 */
class PriceListItem extends Model
{
    /** @use HasFactory<PriceListItemFactory> */
    use HasFactory;

    use HasUuids;

    protected $fillable = [
        'price_list_id',
        'name',
        'description',
        'unit',
        'unit_price',
        'vat_rate',
        'is_active',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'unit_price' => 'decimal:2',
            'vat_rate' => 'decimal:2',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    // ── Computed ──────────────────────────────────────────────────────────────

    public function getUnitEnumAttribute(): ItemUnit|string
    {
        return ItemUnit::tryFromCustom($this->unit);
    }

    // ── Relations ─────────────────────────────────────────────────────────────

    /** @return BelongsTo<PriceList, $this> */
    public function priceList(): BelongsTo
    {
        return $this->belongsTo(PriceList::class);
    }
}
