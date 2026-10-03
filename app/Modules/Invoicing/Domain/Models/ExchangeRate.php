<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Domain\Models;

use App\Modules\Invoicing\Domain\Enums\ExchangeRateSource;
use App\Modules\Shared\Domain\Contracts\Account;
use App\Modules\Shared\Domain\Contracts\ProvidesInvoiceNumbering;
use App\Modules\Shared\Domain\Contracts\ProvidesSupplierProfile;
use App\Modules\Shared\Enums\Currency;
use App\Modules\Shared\Support\Decimal;
use Barryvdh\LaravelIdeHelper\Eloquent;
use Database\Factories\Modules\Invoicing\Domain\Models\ExchangeRateFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Contracts\Translation\HasLocalePreference;
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
 * @property-read Account|null $user
 *
 * @method static ExchangeRateFactory factory($count = null, $state = [])
 * @method static Builder<static> forPair(Currency $base, Currency $target)
 * @method static Builder<static> newModelQuery()
 * @method static Builder<static> newQuery()
 * @method static Builder<static> query()
 * @method static Builder<static> system()
 * @method static Builder<static> whereBaseCurrency($value)
 * @method static Builder<static> whereCreatedAt($value)
 * @method static Builder<static> whereDate($value)
 * @method static Builder<static> whereId($value)
 * @method static Builder<static> whereRate($value)
 * @method static Builder<static> whereSource($value)
 * @method static Builder<static> whereTargetCurrency($value)
 * @method static Builder<static> whereUpdatedAt($value)
 * @method static Builder<static> whereUserId($value)
 *
 * @mixin Eloquent
 */
class ExchangeRate extends Model
{
    /** @use HasFactory<ExchangeRateFactory> */
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

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeSystem(Builder $query): Builder
    {
        return $query->whereNull('user_id');
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeForPair(Builder $query, Currency $base, Currency $target): Builder
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
        return (float) Decimal::money(Decimal::of($amount)->multipliedBy(Decimal::of($this->rate)));
    }

    // ── Relations ─────────────────────────────────────────────────────────────

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
