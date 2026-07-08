<?php

declare(strict_types=1);

namespace App\Modules\TimeTracking\Domain\Models;

use App\Modules\Auth\Domain\Models\User;
use App\Modules\Shared\Enums\Currency;
use App\Modules\Shared\Traits\HasUserScope;
use Database\Factories\Modules\TimeTracking\Domain\Models\ExpenseFactory;
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
 * @property string $description
 * @property string $category office|travel|software|hardware|marketing|other
 * @property numeric $amount
 * @property Currency $currency
 * @property Carbon $date
 * @property string|null $note
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read User|null $user
 *
 * @method static ExpenseFactory factory($count = null, $state = [])
 * @method static Builder<static>|Expense forUser($userId = null)
 * @method static Builder<static>|Expense newModelQuery()
 * @method static Builder<static>|Expense newQuery()
 * @method static Builder<static>|Expense onlyTrashed()
 * @method static Builder<static>|Expense query()
 * @method static Builder<static>|Expense whereAmount($value)
 * @method static Builder<static>|Expense whereCategory($value)
 * @method static Builder<static>|Expense whereCreatedAt($value)
 * @method static Builder<static>|Expense whereCurrency($value)
 * @method static Builder<static>|Expense whereDate($value)
 * @method static Builder<static>|Expense whereDeletedAt($value)
 * @method static Builder<static>|Expense whereDescription($value)
 * @method static Builder<static>|Expense whereId($value)
 * @method static Builder<static>|Expense whereNote($value)
 * @method static Builder<static>|Expense whereUpdatedAt($value)
 * @method static Builder<static>|Expense whereUserId($value)
 * @method static Builder<static>|Expense withTrashed(bool $withTrashed = true)
 * @method static Builder<static>|Expense withoutTrashed()
 *
 * @mixin Eloquent
 */
class Expense extends Model
{
    use HasFactory;
    use HasUserScope;
    use HasUuids;
    use SoftDeletes;

    protected $fillable = [
        'user_id',
        'description',
        'category',
        'amount',
        'currency',
        'date',
        'note',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'currency' => Currency::class,
            'date' => 'date',
        ];
    }

    // ── Relations ─────────────────────────────────────────────────────────────

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
