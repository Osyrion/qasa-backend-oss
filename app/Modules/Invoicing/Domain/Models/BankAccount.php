<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Domain\Models;

use App\Modules\Auth\Domain\Models\User;
use App\Modules\Shared\Enums\Currency;
use App\Modules\Shared\Traits\HasUserScope;
use Database\Factories\Modules\Invoicing\Domain\Models\BankAccountFactory;
use Eloquent;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $user_id
 * @property string $label
 * @property string|null $bank_name
 * @property string|null $account_number Local format, e.g. 123456789/0100
 * @property string|null $iban Required for payment QR
 * @property string|null $bic
 * @property Currency $currency
 * @property bool $is_default Default account for its currency
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User|null $user
 *
 * @method static BankAccountFactory factory($count = null, $state = [])
 * @method static Builder<static>|BankAccount forUser($userId = null)
 * @method static Builder<static>|BankAccount newModelQuery()
 * @method static Builder<static>|BankAccount newQuery()
 * @method static Builder<static>|BankAccount query()
 * @method static Builder<static>|BankAccount whereAccountNumber($value)
 * @method static Builder<static>|BankAccount whereBankName($value)
 * @method static Builder<static>|BankAccount whereBic($value)
 * @method static Builder<static>|BankAccount whereCreatedAt($value)
 * @method static Builder<static>|BankAccount whereCurrency($value)
 * @method static Builder<static>|BankAccount whereIban($value)
 * @method static Builder<static>|BankAccount whereId($value)
 * @method static Builder<static>|BankAccount whereIsDefault($value)
 * @method static Builder<static>|BankAccount whereLabel($value)
 * @method static Builder<static>|BankAccount whereUpdatedAt($value)
 * @method static Builder<static>|BankAccount whereUserId($value)
 *
 * @mixin Eloquent
 */
class BankAccount extends Model
{
    /** @use HasFactory<BankAccountFactory> */
    use HasFactory;

    use HasUserScope;
    use HasUuids;

    protected $fillable = [
        'user_id',
        'label',
        'bank_name',
        'account_number',
        'iban',
        'bic',
        'currency',
        'is_default',
    ];

    protected function casts(): array
    {
        return [
            'currency' => Currency::class,
            'is_default' => 'boolean',
        ];
    }

    /**
     * Snapshot printed on issued invoices, frozen so later edits
     * don't rewrite existing documents.
     *
     * @return array{label: string, bank_name: string|null, account_number: string|null, iban: string|null, bic: string|null, currency: string}
     */
    public function toSnapshot(): array
    {
        return [
            'label' => $this->label,
            'bank_name' => $this->bank_name,
            'account_number' => $this->account_number,
            'iban' => $this->iban,
            'bic' => $this->bic,
            'currency' => $this->currency->value,
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
}
