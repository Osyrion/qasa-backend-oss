<?php

declare(strict_types=1);

namespace App\Modules\Shared\Domain\Models;

use App\Modules\Auth\Domain\Models\User;
use App\Modules\Shared\Traits\HasUserScope;
use Database\Factories\Modules\Shared\Domain\Models\ActivityLogFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * Append-only audit trail entry — who did what to which record and when.
 * Read-only via the API; written exclusively through ActivityRecorderInterface.
 *
 * @property string $id
 * @property string $user_id
 * @property string|null $actor_id Null for system-triggered events (scheduled commands, queued jobs)
 * @property string $subject_type
 * @property string $subject_id
 * @property string $event e.g. invoice.status_changed
 * @property array<string, mixed>|null $changes Old/new values, shape varies per event
 * @property Carbon|null $created_at
 * @property-read User|null $user
 * @property-read User|null $actor
 * @property-read Model|null $subject
 *
 * @method static ActivityLogFactory factory($count = null, $state = [])
 * @method static \Illuminate\Database\Eloquent\Builder<static> forUser($userId = null)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ActivityLog newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ActivityLog newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ActivityLog query()
 *
 * @mixin \Eloquent
 */
class ActivityLog extends Model
{
    /** @use HasFactory<ActivityLogFactory> */
    use HasFactory;

    use HasUserScope;
    use HasUuids;

    protected $table = 'activity_log';

    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'actor_id',
        'subject_type',
        'subject_id',
        'event',
        'changes',
    ];

    protected function casts(): array
    {
        return [
            'changes' => 'array',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $entry): void {
            $entry->created_at ??= now();
        });
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function subject(): MorphTo
    {
        return $this->morphTo();
    }
}
