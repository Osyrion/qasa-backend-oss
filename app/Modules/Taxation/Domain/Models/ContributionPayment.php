<?php

declare(strict_types=1);

namespace App\Modules\Taxation\Domain\Models;

use App\Modules\Auth\Domain\Models\User;
use App\Modules\Shared\Enums\Currency;
use App\Modules\Shared\Traits\HasUserScope;
use App\Modules\Taxation\Domain\Enums\ContributionType;
use Database\Factories\Modules\Taxation\Domain\Models\ContributionPaymentFactory;
use Eloquent;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $user_id
 * @property ContributionType $type
 * @property int $period_year
 * @property int|null $period_month
 * @property numeric $amount
 * @property Currency $currency
 * @property Carbon $paid_at
 * @property string|null $note
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read User|null $user
 *
 * @method static ContributionPaymentFactory factory($count = null, $state = [])
 * @method static Builder<static>|ContributionPayment forUser($userId = null)
 * @method static Builder<static>|ContributionPayment newModelQuery()
 * @method static Builder<static>|ContributionPayment newQuery()
 * @method static Builder<static>|ContributionPayment onlyTrashed()
 * @method static Builder<static>|ContributionPayment query()
 * @method static Builder<static>|ContributionPayment withTrashed(bool $withTrashed = true)
 * @method static Builder<static>|ContributionPayment withoutTrashed()
 *
 * @mixin Eloquent
 */
class ContributionPayment extends Model
{
    /** @use HasFactory<ContributionPaymentFactory> */
    use HasFactory;

    use HasUserScope;
    use HasUuids;
    use SoftDeletes;

    protected $fillable = [
        'user_id',
        'type',
        'period_year',
        'period_month',
        'amount',
        'currency',
        'paid_at',
        'note',
    ];

    protected function casts(): array
    {
        return [
            'type' => ContributionType::class,
            'period_year' => 'integer',
            'period_month' => 'integer',
            'amount' => 'decimal:2',
            'currency' => Currency::class,
            'paid_at' => 'date',
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
