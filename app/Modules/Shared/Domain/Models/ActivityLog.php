<?php

declare(strict_types=1);

namespace App\Modules\Shared\Domain\Models;

use App\Modules\Shared\Domain\Contracts\Account;
use App\Modules\Shared\Domain\Contracts\ProvidesInvoiceNumbering;
use App\Modules\Shared\Domain\Contracts\ProvidesSupplierProfile;
use App\Modules\Shared\Traits\HasUserScope;
use Database\Factories\Modules\Shared\Domain\Models\ActivityLogFactory;
use Eloquent;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Contracts\Translation\HasLocalePreference;
use Illuminate\Database\Eloquent\Builder;
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
 * @property string $event e.g. invoice.sent
 * @property array<string, mixed>|null $changes Old/new values, shape varies per event
 * @property string|null $prev_hash SHA-256 of the account's previous entry, null for the chain's first row
 * @property string|null $row_hash SHA-256 of this entry's own canonical fields + prev_hash
 * @property Carbon|null $created_at
 * @property-read Account|null $actor
 * @property-read Model|null $subject
 *
 * @method static ActivityLogFactory factory($count = null, $state = [])
 * @method static Builder<static> forUser($userId = null)
 * @method static Builder<static> newModelQuery()
 * @method static Builder<static> newQuery()
 * @method static Builder<static> query()
 *
 * @mixin Eloquent
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
        // Settable explicitly so the timestamp used to compute row_hash and
        // the one actually persisted are the exact same value — passing it
        // to create() while it wasn't fillable used to be silently dropped
        // in favour of a fresh now() from the creating hook below, a few
        // microseconds off from whatever was hashed.
        'created_at',
        'prev_hash',
        'row_hash',
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
     * Canonical SHA-256 for one hash-chain link — the single implementation
     * EloquentActivityRecorder (new rows), the backfill migration (existing
     * rows) and qasa:activity:verify-chain (checking either) all share, so
     * the three can never quietly compute it three different ways.
     *
     * Field order is fixed and part of the hash's meaning: changing it
     * changes every hash computed from it, which would look identical to
     * tampering to every row already written.
     *
     * @param  array<string, mixed>|null  $changes
     */
    public static function computeRowHash(
        string $userId,
        ?string $actorId,
        string $subjectType,
        string $subjectId,
        string $event,
        ?array $changes,
        string $createdAtIso,
        ?string $prevHash,
    ): string {
        $canonical = [
            'user_id' => $userId,
            'actor_id' => $actorId,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'event' => $event,
            'changes' => $changes,
            'created_at' => $createdAtIso,
            'prev_hash' => $prevHash,
        ];

        return hash('sha256', json_encode($canonical, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    /**
     * @return BelongsTo<Model&Account&MustVerifyEmail&HasLocalePreference&ProvidesInvoiceNumbering&ProvidesSupplierProfile, $this>
     */
    public function actor(): BelongsTo
    {
        /** @var class-string<Model&Account&MustVerifyEmail&HasLocalePreference&ProvidesInvoiceNumbering&ProvidesSupplierProfile> $account */
        $account = config('auth.providers.users.model');

        return $this->belongsTo($account, 'actor_id');
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function subject(): MorphTo
    {
        return $this->morphTo();
    }
}
