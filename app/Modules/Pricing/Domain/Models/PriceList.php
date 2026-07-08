<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Domain\Models;

use App\Modules\Auth\Domain\Models\User;
use App\Modules\Shared\Enums\Currency;
use App\Modules\Shared\Traits\HasUserScope;
use Database\Factories\Modules\Pricing\Domain\Models\PriceListFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * User-global catalog of services/products, optionally segmented
 * by currency and country.
 *
 * @property string $id
 * @property string $user_id
 * @property string $name
 * @property string|null $description
 * @property Currency|null $currency Segmentation; null = any currency
 * @property string|null $country ISO 3166-1 alpha-2; null = any country
 * @property bool $is_default
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read User|null $user
 * @property-read Collection<int, PriceListItem> $items
 */
class PriceList extends Model
{
    /** @use HasFactory<PriceListFactory> */
    use HasFactory;

    use HasUserScope;
    use HasUuids;
    use SoftDeletes;

    protected $fillable = [
        'user_id',
        'name',
        'description',
        'currency',
        'country',
        'is_default',
    ];

    protected function casts(): array
    {
        return [
            'currency' => Currency::class,
            'is_default' => 'boolean',
        ];
    }

    // ── Relations ─────────────────────────────────────────────────────────────

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<PriceListItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(PriceListItem::class)->orderBy('sort_order');
    }
}
