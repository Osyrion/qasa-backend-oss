<?php

declare(strict_types=1);

namespace App\Modules\Auth\Domain\Models;

use App\Modules\Shared\Traits\HasUserScope;
use Eloquent;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * An outstanding SMS one-time code. See the create migration for why the
 * code itself is never stored.
 *
 * @property string $id
 * @property string $user_id
 * @property string $phone
 * @property string $code_hash
 * @property int $attempts
 * @property Carbon $expires_at
 * @property Carbon|null $consumed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 *
 * @method static Builder<static> newModelQuery()
 * @method static Builder<static> newQuery()
 * @method static Builder<static> query()
 *
 * @mixin Eloquent
 */
class PhoneVerificationCode extends Model
{
    use HasUserScope;
    use HasUuids;

    protected $fillable = [
        'user_id',
        'phone',
        'code_hash',
        'attempts',
        'expires_at',
        'consumed_at',
    ];

    /**
     * Never let a code hash reach a response, however this model is
     * serialised — no endpoint returns one today, and none should start.
     *
     * @var list<string>
     */
    protected $hidden = ['code_hash'];

    /**
     * Still usable: not spent, not expired, guesses left.
     */
    public function isLive(): bool
    {
        return $this->consumed_at === null
            && $this->expires_at->isFuture()
            && $this->attempts < (int) config('qasa.phone_verification.max_attempts', 5);
    }

    protected function casts(): array
    {
        return [
            'attempts' => 'integer',
            'expires_at' => 'datetime',
            'consumed_at' => 'datetime',
        ];
    }
}
