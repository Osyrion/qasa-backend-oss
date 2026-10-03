<?php

declare(strict_types=1);

namespace App\Modules\Orders\Domain\Models;

use App\Modules\Clients\Domain\Models\Client;
use App\Modules\Orders\Domain\Enums\OrderStatus;
use App\Modules\Shared\Enums\BillingType;
use App\Modules\Shared\Enums\Currency;
use App\Modules\Shared\Traits\HasUserScope;
use Database\Factories\Modules\Orders\Domain\Models\OrderFactory;
use Eloquent;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $user_id
 * @property string|null $client_id
 * @property string $name
 * @property string|null $color Hex color
 * @property string|null $readme Markdown — brief, description, scope
 * @property string $status
 * @property BillingType $billing_type
 * @property numeric|null $rate Default rate per billing unit
 * @property Currency|null $currency Overrides client currency
 * @property numeric|null $estimated_hours
 * @property numeric|null $estimated_price Excl. VAT
 * @property Carbon|null $deadline
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read Collection<int, OrderAttachment> $attachments
 * @property-read int|null $attachments_count
 * @property-read Client|null $client
 * @property-read Collection<int, OrderItem> $items
 * @property-read int|null $items_count
 * @property-read Collection<int, OrderNote> $notes
 * @property-read int|null $notes_count
 * @property-read OrderStatus|null $status_enum
 *
 * @method static Builder<static> active()
 * @method static Builder<static> billable()
 * @method static OrderFactory factory($count = null, $state = [])
 * @method static Builder<static> forUser($userId = null)
 * @method static Builder<static> newModelQuery()
 * @method static Builder<static> newQuery()
 * @method static Builder<static> onlyTrashed()
 * @method static Builder<static> personal()
 * @method static Builder<static> query()
 * @method static Builder<static> whereBillingType($value)
 * @method static Builder<static> whereClientId($value)
 * @method static Builder<static> whereColor($value)
 * @method static Builder<static> whereCreatedAt($value)
 * @method static Builder<static> whereCurrency($value)
 * @method static Builder<static> whereDeadline($value)
 * @method static Builder<static> whereDeletedAt($value)
 * @method static Builder<static> whereEstimatedHours($value)
 * @method static Builder<static> whereEstimatedPrice($value)
 * @method static Builder<static> whereId($value)
 * @method static Builder<static> whereName($value)
 * @method static Builder<static> whereRate($value)
 * @method static Builder<static> whereReadme($value)
 * @method static Builder<static> whereStatus($value)
 * @method static Builder<static> whereUpdatedAt($value)
 * @method static Builder<static> whereUserId($value)
 * @method static Builder<static> withTrashed(bool $withTrashed = true)
 * @method static Builder<static> withoutTrashed()
 *
 * @mixin Eloquent
 */
class Order extends Model
{
    /** @use HasFactory<OrderFactory> */
    use HasFactory;

    use HasUserScope;
    use HasUuids;
    use SoftDeletes;

    protected $fillable = [
        'user_id',
        'client_id',
        'name',
        'color',
        'readme',
        'status',
        'billing_type',
        'rate',
        'currency',
        'estimated_hours',
        'estimated_price',
        'deadline',
    ];

    protected function casts(): array
    {
        return [
            'billing_type' => BillingType::class,
            'currency' => Currency::class,
            'rate' => 'decimal:2',
            'estimated_hours' => 'decimal:2',
            'estimated_price' => 'decimal:2',
            'deadline' => 'date',
        ];
    }

    // ── Scopes ────────────────────────────────────────────────────────────────

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeBillable(Builder $query): Builder
    {
        return $query->whereNotNull('client_id');
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopePersonal(Builder $query): Builder
    {
        return $query->whereNull('client_id');
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }

    // ── Computed ──────────────────────────────────────────────────────────────

    public function getStatusEnumAttribute(): ?OrderStatus
    {
        return OrderStatus::tryFrom($this->status);
    }

    public function isPersonal(): bool
    {
        return $this->client_id === null;
    }

    public function isBillable(): bool
    {
        return $this->client_id !== null;
    }

    public function hasDefaultRate(): bool
    {
        return $this->billing_type->hasDefaultRate() && $this->rate !== null;
    }

    public function effectiveCurrency(): Currency
    {
        return $this->currency
            ?? $this->client->currency
            ?? $this->user?->supplierProfile()->defaultCurrency
            ?? Currency::EUR;
    }

    // ── Relations ─────────────────────────────────────────────────────────────

    /**
     * @return BelongsTo<Client, $this>
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /**
     * @return HasMany<OrderNote, $this>
     */
    public function notes(): HasMany
    {
        return $this->hasMany(OrderNote::class)->orderBy('created_at', 'desc');
    }

    /**
     * @return HasMany<OrderAttachment, $this>
     */
    public function attachments(): HasMany
    {
        return $this->hasMany(OrderAttachment::class)->orderBy('sort_order');
    }

    /**
     * @return HasMany<OrderItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class)->orderBy('sort_order');
    }
}
