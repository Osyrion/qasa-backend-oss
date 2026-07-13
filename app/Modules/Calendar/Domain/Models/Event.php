<?php

declare(strict_types=1);

namespace App\Modules\Calendar\Domain\Models;

use App\Modules\Auth\Domain\Models\User;
use App\Modules\Calendar\Domain\Enums\EventSource;
use App\Modules\Orders\Domain\Models\Order;
use App\Modules\Shared\Traits\HasUserScope;
use Database\Factories\Modules\Calendar\Domain\Models\EventFactory;
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
 * @property string|null $order_id
 * @property string $title
 * @property string|null $description
 * @property string|null $location
 * @property string|null $color
 * @property bool $is_all_day
 * @property Carbon $starts_at
 * @property Carbon $ends_at Exclusive; midnight stored as next-day 00:00
 * @property EventSource $source
 * @property string|null $external_uid ICS UID / import hash for dedupe & future external sync
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read Order|null $order
 * @property-read User|null $user
 *
 * @method static EventFactory factory($count = null, $state = [])
 * @method static Builder<static>|Event forUser($userId = null)
 * @method static Builder<static>|Event newModelQuery()
 * @method static Builder<static>|Event newQuery()
 * @method static Builder<static>|Event onlyTrashed()
 * @method static Builder<static>|Event query()
 * @method static Builder<static>|Event whereColor($value)
 * @method static Builder<static>|Event whereCreatedAt($value)
 * @method static Builder<static>|Event whereDeletedAt($value)
 * @method static Builder<static>|Event whereDescription($value)
 * @method static Builder<static>|Event whereEndsAt($value)
 * @method static Builder<static>|Event whereExternalUid($value)
 * @method static Builder<static>|Event whereId($value)
 * @method static Builder<static>|Event whereIsAllDay($value)
 * @method static Builder<static>|Event whereLocation($value)
 * @method static Builder<static>|Event whereSource($value)
 * @method static Builder<static>|Event whereStartsAt($value)
 * @method static Builder<static>|Event whereTitle($value)
 * @method static Builder<static>|Event whereUpdatedAt($value)
 * @method static Builder<static>|Event whereUserId($value)
 * @method static Builder<static>|Event withTrashed(bool $withTrashed = true)
 * @method static Builder<static>|Event withoutTrashed()
 *
 * @mixin Eloquent
 */
class Event extends Model
{
    /** @use HasFactory<EventFactory> */
    use HasFactory;

    use HasUserScope;
    use HasUuids;
    use SoftDeletes;

    protected $fillable = [
        'user_id',
        'order_id',
        'title',
        'description',
        'location',
        'color',
        'is_all_day',
        'starts_at',
        'ends_at',
        'source',
        'external_uid',
    ];

    protected function casts(): array
    {
        return [
            'is_all_day' => 'boolean',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'source' => EventSource::class,
        ];
    }

    // ── Computed ──────────────────────────────────────────────────────────────

    public function durationMinutes(): int
    {
        return (int) $this->starts_at->diffInMinutes($this->ends_at);
    }

    public function endsAtMidnight(): bool
    {
        return $this->ends_at->equalTo($this->ends_at->clone()->startOfDay());
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
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
