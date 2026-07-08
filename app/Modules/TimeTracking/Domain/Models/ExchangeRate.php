<?php

declare(strict_types=1);

namespace App\Modules\TimeTracking\Domain\Models;

use App\Modules\Auth\Domain\Models\User;
use App\Modules\Shared\Enums\Currency;
use App\Modules\TimeTracking\Domain\Enums\ExchangeRateSource;
use Database\Factories\Modules\TimeTracking\Domain\Models\ExchangeRateFactory;
use Eloquent;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string|null $user_id
 * @property Currency $base_currency
 * @property Currency $target_currency
 * @property numeric $rate
 * @property Carbon $date
 * @property ExchangeRateSource $source
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User|null $user
 *
 * @method static ExchangeRateFactory factory($count = null, $state = [])
 * @method static Builder<static>|ExchangeRate forPair(\App\Modules\Shared\Enums\Currency $base, \App\Modules\Shared\Enums\Currency $target)
 * @method static Builder<static>|ExchangeRate newModelQuery()
 * @method static Builder<static>|ExchangeRate newQuery()
 * @method static Builder<static>|ExchangeRate query()
 * @method static Builder<static>|ExchangeRate system()
 * @method static Builder<static>|ExchangeRate whereBaseCurrency($value)
 * @method static Builder<static>|ExchangeRate whereCreatedAt($value)
 * @method static Builder<static>|ExchangeRate whereDate($value)
 * @method static Builder<static>|ExchangeRate whereId($value)
 * @method static Builder<static>|ExchangeRate whereRate($value)
 * @method static Builder<static>|ExchangeRate whereSource($value)
 * @method static Builder<static>|ExchangeRate whereTargetCurrency($value)
 * @method static Builder<static>|ExchangeRate whereUpdatedAt($value)
 * @method static Builder<static>|ExchangeRate whereUserId($value)
 *
 * @mixin Eloquent
 */
class ExchangeRate extends Model
{
    use HasFactory;
    use HasUuids;

    protected $fillable = [
        'user_id',
        'base_currency',
        'target_currency',
        'rate',
        'date',
        'source',
    ];

    protected function casts(): array
    {
        return [
            'base_currency' => Currency::class,
            'target_currency' => Currency::class,
            'rate' => 'decimal:6',
            'date' => 'date',
            'source' => ExchangeRateSource::class,
        ];
    }

    // ── Scopes ────────────────────────────────────────────────────────────────

    public function scopeSystem($query)
    {
        return $query->whereNull('user_id');
    }

    public function scopeForPair($query, Currency $base, Currency $target)
    {
        return $query
            ->where('base_currency', $base->value)
            ->where('target_currency', $target->value);
    }

    // ── Computed ──────────────────────────────────────────────────────────────

    public function isSystemRate(): bool
    {
        return $this->user_id === null;
    }

    public function convert(float $amount): float
    {
        return round($amount * (float) $this->rate, 2);
    }

    // ── Relations ─────────────────────────────────────────────────────────────

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
