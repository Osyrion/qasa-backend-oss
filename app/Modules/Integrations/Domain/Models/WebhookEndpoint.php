<?php

declare(strict_types=1);

namespace App\Modules\Integrations\Domain\Models;

use App\Modules\Auth\Domain\Models\User;
use App\Modules\Shared\Traits\HasUserScope;
use Database\Factories\Modules\Integrations\Domain\Models\WebhookEndpointFactory;
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
 * @property string $url
 * @property string $secret Server-generated, encrypted at rest; returned to the client only once, at creation
 * @property list<string> $events Subset of WebhookEventMap wire event names
 * @property bool $is_active
 * @property int $consecutive_failures
 * @property Carbon|null $disabled_at Set once consecutive_failures hits the auto-disable threshold
 * @property Carbon|null $last_success_at
 * @property Carbon|null $last_failure_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read User|null $user
 * @property-read Collection<int, WebhookDelivery> $deliveries
 *
 * @method static WebhookEndpointFactory factory($count = null, $state = [])
 *
 * @property-read int|null $deliveries_count
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WebhookEndpoint forUser(?string $userId = null)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WebhookEndpoint newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WebhookEndpoint newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WebhookEndpoint onlyTrashed()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WebhookEndpoint query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WebhookEndpoint whereConsecutiveFailures($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WebhookEndpoint whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WebhookEndpoint whereDeletedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WebhookEndpoint whereDisabledAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WebhookEndpoint whereEvents($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WebhookEndpoint whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WebhookEndpoint whereIsActive($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WebhookEndpoint whereLastFailureAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WebhookEndpoint whereLastSuccessAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WebhookEndpoint whereSecret($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WebhookEndpoint whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WebhookEndpoint whereUrl($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WebhookEndpoint whereUserId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WebhookEndpoint withTrashed(bool $withTrashed = true)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WebhookEndpoint withoutTrashed()
 *
 * @mixin \Eloquent
 */
class WebhookEndpoint extends Model
{
    /** @use HasFactory<WebhookEndpointFactory> */
    use HasFactory;

    use HasUserScope;
    use HasUuids;
    use SoftDeletes;

    protected $fillable = [
        'user_id',
        'url',
        'secret',
        'events',
        'is_active',
        'consecutive_failures',
        'disabled_at',
        'last_success_at',
        'last_failure_at',
    ];

    protected $hidden = [
        'secret',
    ];

    protected function casts(): array
    {
        return [
            'secret' => 'encrypted',
            'events' => 'array',
            'is_active' => 'boolean',
            'consecutive_failures' => 'integer',
            'disabled_at' => 'datetime',
            'last_success_at' => 'datetime',
            'last_failure_at' => 'datetime',
        ];
    }

    public function subscribesTo(string $wireEvent): bool
    {
        return $this->is_active && in_array($wireEvent, $this->events, true);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<WebhookDelivery, $this>
     */
    public function deliveries(): HasMany
    {
        return $this->hasMany(WebhookDelivery::class);
    }
}
