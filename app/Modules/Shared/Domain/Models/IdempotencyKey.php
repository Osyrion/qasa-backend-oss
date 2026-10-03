<?php

declare(strict_types=1);

namespace App\Modules\Shared\Domain\Models;

use App\Modules\Shared\Domain\Contracts\Account;
use App\Modules\Shared\Domain\Contracts\ProvidesInvoiceNumbering;
use App\Modules\Shared\Domain\Contracts\ProvidesSupplierProfile;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Contracts\Translation\HasLocalePreference;
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
 * @property int|null $response_status null while the claim is in flight — the
 *                                     row exists to hold the key, the response has not been produced yet
 * @property array<string, mixed>|null $response_body
 * @property Carbon|null $created_at
 * @property-read Account|null $user
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static> newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static> newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static> query()
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
     * @return BelongsTo<Model&Account&MustVerifyEmail&HasLocalePreference&ProvidesInvoiceNumbering&ProvidesSupplierProfile, $this>
     */
    public function user(): BelongsTo
    {
        /** @var class-string<Model&Account&MustVerifyEmail&HasLocalePreference&ProvidesInvoiceNumbering&ProvidesSupplierProfile> $account */
        $account = config('auth.providers.users.model');

        return $this->belongsTo($account);
    }
}
