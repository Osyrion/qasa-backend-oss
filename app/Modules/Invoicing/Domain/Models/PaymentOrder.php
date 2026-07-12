<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Domain\Models;

use App\Modules\Auth\Domain\Models\User;
use App\Modules\Shared\Enums\Currency;
use App\Modules\Shared\Traits\HasUserScope;
use Database\Factories\Modules\Invoicing\Domain\Models\PaymentOrderFactory;
use Eloquent;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * A payment batch handed to the bank. Rows are frozen snapshots
 * (payment_order_items) so a re-download is byte-identical to the original
 * export regardless of later invoice edits.
 *
 * @property string $id
 * @property string $user_id
 * @property string|null $bank_account_id
 * @property array<string, mixed> $payer_snapshot Frozen payer account: label, number, IBAN, BIC, currency
 * @property Currency $currency
 * @property Carbon $due_date
 * @property string|null $constant_symbol
 * @property string|null $note
 * @property int $items_count
 * @property numeric $total_amount
 * @property bool $marked_paid
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read BankAccount|null $bankAccount
 * @property-read Collection<int, PaymentOrderItem> $items
 * @property-read User|null $user
 *
 * @method static PaymentOrderFactory factory($count = null, $state = [])
 * @method static Builder<static>|PaymentOrder forUser($userId = null)
 * @method static Builder<static>|PaymentOrder newModelQuery()
 * @method static Builder<static>|PaymentOrder newQuery()
 * @method static Builder<static>|PaymentOrder onlyTrashed()
 * @method static Builder<static>|PaymentOrder query()
 * @method static Builder<static>|PaymentOrder withTrashed(bool $withTrashed = true)
 * @method static Builder<static>|PaymentOrder withoutTrashed()
 *
 * @mixin Eloquent
 */
class PaymentOrder extends Model
{
    /** @use HasFactory<PaymentOrderFactory> */
    use HasFactory;

    use HasUserScope;
    use HasUuids;
    use SoftDeletes;

    protected $fillable = [
        'user_id',
        'bank_account_id',
        'payer_snapshot',
        'currency',
        'due_date',
        'constant_symbol',
        'note',
        'items_count',
        'total_amount',
        'marked_paid',
    ];

    protected function casts(): array
    {
        return [
            'payer_snapshot' => 'array',
            'currency' => Currency::class,
            'due_date' => 'date',
            'total_amount' => 'decimal:2',
            'marked_paid' => 'boolean',
        ];
    }

    // ── Relations ─────────────────────────────────────────────────────────────

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<BankAccount, $this>
     */
    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class);
    }

    /**
     * @return HasMany<PaymentOrderItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(PaymentOrderItem::class)->orderBy('sort_order');
    }
}
