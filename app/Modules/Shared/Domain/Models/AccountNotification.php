<?php

declare(strict_types=1);

namespace App\Modules\Shared\Domain\Models;

use App\Modules\Auth\Domain\Models\User;
use App\Modules\Shared\Traits\HasUserScope;
use Eloquent;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Carbon;

/**
 * An in-app notification.
 *
 * Laravel's DatabaseNotification plus the account it belongs to, which is
 * what makes it a tenant-owned record like everything else: HasUserScope on
 * `user_id` (the account owner) and the matching RLS policy. `notifiable_id`
 * stays what the framework means by it — the recipient — and is what
 * NotificationPolicy checks, since a team member must not read the owner's
 * notifications even though both rows sit in the same account.
 *
 * @property string $id
 * @property string $type
 * @property string $notifiable_type
 * @property string $notifiable_id
 * @property string $user_id Account owner
 * @property array<string, mixed> $data
 * @property Carbon|null $read_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User|null $user
 *
 * @method static Builder<static> forUser($userId = null)
 * @method static Builder<static>|AccountNotification newModelQuery()
 * @method static Builder<static>|AccountNotification newQuery()
 * @method static Builder<static>|AccountNotification query()
 *
 * @mixin Eloquent
 */
class AccountNotification extends DatabaseNotification
{
    use HasUserScope;

    protected $table = 'notifications';

    /** @var list<string> */
    protected $fillable = [
        'id',
        'type',
        'notifiable_type',
        'notifiable_id',
        'user_id',
        'data',
        'read_at',
    ];

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Notifications addressed to one specific recipient inside the account.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeForRecipient(Builder $query, string $userId): Builder
    {
        return $query->where('notifiable_id', $userId);
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeCategory(Builder $query, string $category): Builder
    {
        return $query->where('data->category', $category);
    }
}
