<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Domain\Models;

use App\Modules\Auth\Domain\Models\User;
use App\Modules\Invoicing\Domain\Enums\CashDocumentType;
use App\Modules\Shared\Enums\Currency;
use App\Modules\Shared\Traits\HasUserScope;
use Database\Factories\Modules\Invoicing\Domain\Models\CashDocumentFactory;
use Eloquent;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * A cash receipt or payment (PPD/VPD).
 *
 * Append-only by design: no update path, no delete. A mistake is corrected
 * by issuing the opposite document through `reverses_cash_document_id` —
 * both stay in the book and cancel out, which is what a paper cash book
 * does and what an auditor expects to see.
 *
 * `invoice_payment_id` / `expense_id` are the difference between "a receipt
 * documenting money already recorded" and "money the system would otherwise
 * not know about". TaxIncomeAggregator counts only the latter; without that
 * distinction every cash-paid invoice would double its income.
 *
 * @property string $id
 * @property string $user_id
 * @property CashDocumentType $type
 * @property string $number
 * @property Carbon $issued_at
 * @property numeric $amount Always positive; direction comes from `type`
 * @property Currency $currency
 * @property numeric|null $vat_rate
 * @property numeric|null $vat_amount
 * @property string|null $counterparty
 * @property string $description
 * @property string|null $note
 * @property string|null $invoice_payment_id Set when this only documents an already-recorded payment
 * @property string|null $expense_id Set when this only documents an already-recorded expense
 * @property string|null $reverses_cash_document_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User|null $user
 * @property-read InvoicePayment|null $invoicePayment
 * @property-read Expense|null $expense
 * @property-read CashDocument|null $reverses
 * @property-read CashDocument|null $reversal
 *
 * @method static CashDocumentFactory factory($count = null, $state = [])
 * @method static Builder<static> forUser($userId = null)
 * @method static Builder<static>|CashDocument newModelQuery()
 * @method static Builder<static>|CashDocument newQuery()
 * @method static Builder<static>|CashDocument query()
 *
 * @mixin Eloquent
 */
class CashDocument extends Model
{
    /** @use HasFactory<CashDocumentFactory> */
    use HasFactory;

    use HasUserScope;
    use HasUuids;

    protected $fillable = [
        'user_id',
        'type',
        'number',
        'issued_at',
        'amount',
        'currency',
        'vat_rate',
        'vat_amount',
        'counterparty',
        'description',
        'note',
        'invoice_payment_id',
        'expense_id',
        'reverses_cash_document_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => CashDocumentType::class,
            'currency' => Currency::class,
            'issued_at' => 'date',
            'amount' => 'decimal:2',
            'vat_rate' => 'decimal:2',
            'vat_amount' => 'decimal:2',
        ];
    }

    /**
     * Documents money the system knows about only through this record.
     *
     * The one question the tax aggregator asks, phrased once here so it
     * cannot drift between the aggregator and the cash book.
     */
    public function isStandalone(): bool
    {
        return $this->invoice_payment_id === null && $this->expense_id === null;
    }

    public function isReversal(): bool
    {
        return $this->reverses_cash_document_id !== null;
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<InvoicePayment, $this>
     */
    public function invoicePayment(): BelongsTo
    {
        return $this->belongsTo(InvoicePayment::class);
    }

    /**
     * @return BelongsTo<Expense, $this>
     */
    public function expense(): BelongsTo
    {
        return $this->belongsTo(Expense::class);
    }

    /**
     * @return BelongsTo<self, $this>
     */
    public function reverses(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reverses_cash_document_id');
    }

    /**
     * @return HasOne<self, $this>
     */
    public function reversal(): HasOne
    {
        return $this->hasOne(self::class, 'reverses_cash_document_id');
    }
}
