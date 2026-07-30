<?php

declare(strict_types=1);

namespace App\Modules\Shared\Domain\Models;

use App\Modules\Auth\Domain\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One stored response per (Idempotency-Key, user, route) — see
 * Shared\Presentation\Middleware\IdempotencyKey for the read/write logic.
 *
 * @property string $id
 * @property string $user_id
 * @property string $key_hash
 * @property string $body_hash
 * @property int $response_status
 * @property array<string, mixed>|null $response_body
 * @property Carbon|null $created_at
 * @property-read User|null $user
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|IdempotencyKey newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|IdempotencyKey newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|IdempotencyKey query()
 *
 * @mixin \Eloquent
 */
class IdempotencyKey extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'key_hash',
        'body_hash',
        'response_status',
        'response_body',
    ];

    protected function casts(): array
    {
        return [
            'response_status' => 'integer',
            'response_body' => 'array',
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
}
