<?php

declare(strict_types=1);

namespace App\Modules\Orders\Domain\Models;

use App\Modules\Auth\Domain\Models\User;
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
 * @property-read User|null $user
 *
 * @method static Builder<static>|Order active()
 * @method static Builder<static>|Order billable()
 * @method static OrderFactory factory($count = null, $state = [])
 * @method static Builder<static>|Order forUser($userId = null)
 * @method static Builder<static>|Order newModelQuery()
 * @method static Builder<static>|Order newQuery()
 * @method static Builder<static>|Order onlyTrashed()
 * @method static Builder<static>|Order personal()
 * @method static Builder<static>|Order query()
 * @method static Builder<static>|Order whereBillingType($value)
 * @method static Builder<static>|Order whereClientId($value)
 * @method static Builder<static>|Order whereColor($value)
 * @method static Builder<static>|Order whereCreatedAt($value)
 * @method static Builder<static>|Order whereCurrency($value)
 * @method static Builder<static>|Order whereDeadline($value)
 * @method static Builder<static>|Order whereDeletedAt($value)
 * @method static Builder<static>|Order whereEstimatedHours($value)
 * @method static Builder<static>|Order whereEstimatedPrice($value)
 * @method static Builder<static>|Order whereId($value)
 * @method static Builder<static>|Order whereName($value)
 * @method static Builder<static>|Order whereRate($value)
 * @method static Builder<static>|Order whereReadme($value)
 * @method static Builder<static>|Order whereStatus($value)
 * @method static Builder<static>|Order whereUpdatedAt($value)
 * @method static Builder<static>|Order whereUserId($value)
 * @method static Builder<static>|Order withTrashed(bool $withTrashed = true)
 * @method static Builder<static>|Order withoutTrashed()
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
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeBillable(Builder $query): Builder
    {
        return $query->whereNotNull('client_id');
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopePersonal(Builder $query): Builder
    {
        return $query->whereNull('client_id');
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
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
        /** @var User $user */
        $user = $this->user;

        return $this->currency
            ?? $this->client->currency
            ?? $user->default_currency;
    }

    // ── Relations ─────────────────────────────────────────────────────────────

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

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
