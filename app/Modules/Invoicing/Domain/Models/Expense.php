<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Domain\Models;

use App\Modules\Shared\Enums\Currency;
use App\Modules\Shared\Traits\HasUserScope;
use Database\Factories\Modules\Invoicing\Domain\Models\ExpenseFactory;
use Eloquent;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
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
 * @property string|null $attachment_disk
 * @property string|null $attachment_path
 * @property string|null $attachment_filename
 * @property string|null $attachment_mime_type
 * @property int|null $attachment_size_bytes
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 *
 * @method static ExpenseFactory factory($count = null, $state = [])
 * @method static Builder<static> forUser($userId = null)
 * @method static Builder<static> newModelQuery()
 * @method static Builder<static> newQuery()
 * @method static Builder<static> onlyTrashed()
 * @method static Builder<static> query()
 * @method static Builder<static> whereAmount($value)
 * @method static Builder<static> whereCategory($value)
 * @method static Builder<static> whereCreatedAt($value)
 * @method static Builder<static> whereCurrency($value)
 * @method static Builder<static> whereDate($value)
 * @method static Builder<static> whereDeletedAt($value)
 * @method static Builder<static> whereDescription($value)
 * @method static Builder<static> whereId($value)
 * @method static Builder<static> whereNote($value)
 * @method static Builder<static> whereUpdatedAt($value)
 * @method static Builder<static> whereUserId($value)
 * @method static Builder<static> withTrashed(bool $withTrashed = true)
 * @method static Builder<static> withoutTrashed()
 *
 * @mixin Eloquent
 */
class Expense extends Model
{
    /** @use HasFactory<ExpenseFactory> */
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
        'attachment_disk',
        'attachment_path',
        'attachment_filename',
        'attachment_mime_type',
        'attachment_size_bytes',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'currency' => Currency::class,
            'date' => 'date',
            'attachment_size_bytes' => 'integer',
        ];
    }

    // ── Computed ──────────────────────────────────────────────────────────────

    public function hasAttachment(): bool
    {
        return $this->attachment_path !== null;
    }
}
