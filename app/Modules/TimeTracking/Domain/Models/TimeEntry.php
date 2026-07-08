<?php

declare(strict_types=1);

namespace App\Modules\TimeTracking\Domain\Models;

use App\Modules\Auth\Domain\Models\User;
use App\Modules\Orders\Domain\Models\Order;
use App\Modules\Orders\Domain\Models\OrderItem;
use App\Modules\Shared\Traits\HasUserScope;
use Database\Factories\Modules\TimeTracking\Domain\Models\TimeEntryFactory;
use Eloquent;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $user_id
 * @property string $order_id
 * @property string|null $order_item_id
 * @property string|null $description
 * @property Carbon $started_at
 * @property Carbon|null $ended_at Null = timer running
 * @property int|null $duration_seconds Computed or manually set
 * @property numeric|null $rate_override Overrides order rate
 * @property numeric $vat_rate VAT rate for this entry
 * @property bool $is_billable
 * @property bool $is_invoiced
 * @property string $source
 * @property string|null $external_id External system ID for deduplication
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read Order|null $order
 * @property-read OrderItem|null $orderItem
 * @property-read User|null $user
 *
 * @method static Builder<static>|TimeEntry billable()
 * @method static TimeEntryFactory factory($count = null, $state = [])
 * @method static Builder<static>|TimeEntry forUser($userId = null)
 * @method static Builder<static>|TimeEntry newModelQuery()
 * @method static Builder<static>|TimeEntry newQuery()
 * @method static Builder<static>|TimeEntry notInvoiced()
 * @method static Builder<static>|TimeEntry onlyTrashed()
 * @method static Builder<static>|TimeEntry query()
 * @method static Builder<static>|TimeEntry running()
 * @method static Builder<static>|TimeEntry whereCreatedAt($value)
 * @method static Builder<static>|TimeEntry whereDeletedAt($value)
 * @method static Builder<static>|TimeEntry whereDescription($value)
 * @method static Builder<static>|TimeEntry whereDurationSeconds($value)
 * @method static Builder<static>|TimeEntry whereEndedAt($value)
 * @method static Builder<static>|TimeEntry whereExternalId($value)
 * @method static Builder<static>|TimeEntry whereId($value)
 * @method static Builder<static>|TimeEntry whereIsBillable($value)
 * @method static Builder<static>|TimeEntry whereIsInvoiced($value)
 * @method static Builder<static>|TimeEntry whereOrderId($value)
 * @method static Builder<static>|TimeEntry whereOrderItemId($value)
 * @method static Builder<static>|TimeEntry whereRateOverride($value)
 * @method static Builder<static>|TimeEntry whereSource($value)
 * @method static Builder<static>|TimeEntry whereStartedAt($value)
 * @method static Builder<static>|TimeEntry whereUpdatedAt($value)
 * @method static Builder<static>|TimeEntry whereUserId($value)
 * @method static Builder<static>|TimeEntry whereVatRate($value)
 * @method static Builder<static>|TimeEntry withTrashed(bool $withTrashed = true)
 * @method static Builder<static>|TimeEntry withoutTrashed()
 *
 * @mixin Eloquent
 */
class TimeEntry extends Model
{
    use HasFactory;
    use HasUserScope;
    use HasUuids;
    use SoftDeletes;

    protected $fillable = [
        'user_id',
        'order_id',
        'order_item_id',
        'description',
        'started_at',
        'ended_at',
        'duration_seconds',
        'rate_override',
        'vat_rate',
        'is_billable',
        'is_invoiced',
        'source',
        'external_id',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
            'duration_seconds' => 'integer',
            'rate_override' => 'decimal:2',
            'vat_rate' => 'decimal:2',
            'is_billable' => 'boolean',
            'is_invoiced' => 'boolean',
        ];
    }

    // ── Scopes ────────────────────────────────────────────────────────────────

    public function scopeRunning($query)
    {
        return $query->whereNull('ended_at');
    }

    public function scopeBillable($query)
    {
        return $query->where('is_billable', true);
    }

    public function scopeNotInvoiced($query)
    {
        return $query->where('is_invoiced', false);
    }

    // ── Computed ──────────────────────────────────────────────────────────────

    public function isRunning(): bool
    {
        return $this->ended_at === null;
    }

    public function isInvoiced(): bool
    {
        return $this->is_invoiced;
    }

    public function effectiveDurationSeconds(): int
    {
        if ($this->duration_seconds !== null) {
            return $this->duration_seconds;
        }

        if ($this->ended_at !== null) {
            return (int) $this->started_at->diffInSeconds($this->ended_at);
        }

        return (int) $this->started_at->diffInSeconds(now());
    }

    public function effectiveDurationHours(): float
    {
        return round($this->effectiveDurationSeconds() / 3600, 2);
    }

    public function formattedDuration(): string
    {
        $seconds = $this->effectiveDurationSeconds();
        $h = intdiv($seconds, 3600);
        $m = intdiv($seconds % 3600, 60);

        return sprintf('%d:%02d', $h, $m);
    }

    /**
     * @deprecated For pricing use \App\Modules\Pricing\Application\Services\RateResolver,
     *             which honours the full rate hierarchy (order > client > user) and
     *             date-effective history. This method only serves quick display.
     */
    public function effectiveRate(): ?float
    {
        if ($this->rate_override !== null) {
            return (float) $this->rate_override;
        }

        return $this->order?->rate ? (float) $this->order->rate : null;
    }

    public function stop(): self
    {
        if ($this->isRunning()) {
            $this->ended_at = now();
            $this->duration_seconds = $this->effectiveDurationSeconds();
        }

        return $this;
    }

    // ── Relations ─────────────────────────────────────────────────────────────

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }
}
