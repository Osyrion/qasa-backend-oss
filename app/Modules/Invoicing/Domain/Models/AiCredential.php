<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Domain\Models;

use App\Modules\Auth\Domain\Models\User;
use App\Modules\Invoicing\Domain\Enums\AiProvider;
use Database\Factories\Modules\Invoicing\Domain\Models\AiCredentialFactory;
use Eloquent;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A BYOK API key for one provider, always keyed to the account owner
 * (accountOwnerId()) — never a team member — one row per (user_id,
 * provider). Resolved by ByokCredentialResolver, verified/invalidated via
 * the ai-credentials API and ProcessInboxItemJob's byok_key_invalid path.
 *
 * @property string $id
 * @property string $user_id Always the account owner
 * @property AiProvider $provider
 * @property string $api_key Encrypted, never exposed via API
 * @property Carbon|null $verified_at Set by POST /ai-credentials/{provider}/test on success
 * @property string|null $last_error Last 401/403 reason — never the key itself
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User $user
 *
 * @method static AiCredentialFactory factory($count = null, $state = [])
 * @method static Builder<static>|AiCredential newModelQuery()
 * @method static Builder<static>|AiCredential newQuery()
 * @method static Builder<static>|AiCredential query()
 *
 * @mixin Eloquent
 */
class AiCredential extends Model
{
    /** @use HasFactory<AiCredentialFactory> */
    use HasFactory;

    use HasUuids;

    protected $fillable = [
        'user_id',
        'provider',
        'api_key',
        'verified_at',
        'last_error',
    ];

    protected $hidden = [
        'api_key',
    ];

    protected function casts(): array
    {
        return [
            'provider' => AiProvider::class,
            'api_key' => 'encrypted',
            'verified_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
