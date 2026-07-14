<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Domain\Models;

use App\Modules\Auth\Domain\Models\User;
use App\Modules\Clients\Domain\Models\Client;
use App\Modules\Orders\Domain\Models\Order;
use App\Modules\Pricing\Domain\Enums\RateLevel;
use App\Modules\Shared\Enums\Currency;
use App\Modules\Shared\Traits\HasUserScope;
use Database\Factories\Modules\Pricing\Domain\Models\RateFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Append-only, date-effective billing rate. Changing a rate means inserting
 * a new row with a newer valid_from — historical rows are never mutated,
 * so past and in-progress (not yet invoiced) work keeps its original pricing.
 *
 * @property string $id
 * @property string $user_id
 * @property RateLevel $level
 * @property string|null $client_id
 * @property string|null $order_id
 * @property numeric|null $rate Rate per billing unit, excl. VAT; null = tombstone (level stops applying from valid_from)
 * @property Currency|null $currency null = inherited effective currency
 * @property Carbon $valid_from
 * @property string|null $note
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User|null $user
 * @property-read Client|null $client
 * @property-read Order|null $order
 *
 * @method static \Database\Factories\Modules\Pricing\Domain\Models\RateFactory factory($count = null, $state = [])
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Rate forUser(?string $userId = null)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Rate newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Rate newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Rate query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Rate whereClientId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Rate whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Rate whereCurrency($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Rate whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Rate whereLevel($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Rate whereNote($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Rate whereOrderId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Rate whereRate($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Rate whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Rate whereUserId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Rate whereValidFrom($value)
 *
 * @mixin \Eloquent
 */
class Rate extends Model
{
    /** @use HasFactory<RateFactory> */
    use HasFactory;

    use HasUserScope;
    use HasUuids;

    protected $fillable = [
        'user_id',
        'level',
        'client_id',
        'order_id',
        'rate',
        'currency',
        'valid_from',
        'note',
    ];

    protected function casts(): array
    {
        return [
            'level' => RateLevel::class,
            'rate' => 'decimal:2',
            'currency' => Currency::class,
            'valid_from' => 'date',
        ];
    }

    // ── Computed ──────────────────────────────────────────────────────────────

    public function isFuture(): bool
    {
        return $this->valid_from->isAfter(today());
    }

    /**
     * Only rates effective from today or later may be deleted — anything
     * older may already have priced past work (append-only history).
     */
    public function isDeletable(): bool
    {
        return $this->valid_from->greaterThanOrEqualTo(today());
    }

    // ── Relations ─────────────────────────────────────────────────────────────

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Client, $this> */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
