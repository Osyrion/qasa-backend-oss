<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Domain\Models;

use App\Modules\Auth\Domain\Models\User;
use App\Modules\Clients\Domain\Models\Client;
use App\Modules\Invoicing\Domain\Enums\SupplierInvoiceStatus;
use App\Modules\Invoicing\Domain\Enums\SupplierVatRegime;
use App\Modules\Shared\Enums\Currency;
use App\Modules\Shared\Traits\HasUserScope;
use Database\Factories\Modules\Invoicing\Domain\Models\SupplierInvoiceFactory;
use Eloquent;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $user_id
 * @property string $client_id
 * @property string $internal_number e.g. DF-2026-001
 * @property string $supplier_invoice_number Original document number as issued by the vendor
 * @property string|null $variable_symbol
 * @property string $status
 * @property SupplierVatRegime $vat_regime
 * @property Carbon $issued_at
 * @property Carbon|null $taxable_supply_at DUZP
 * @property Carbon|null $due_at
 * @property Carbon|null $received_at
 * @property Carbon|null $paid_at
 * @property Currency $currency
 * @property numeric|null $exchange_rate
 * @property numeric $subtotal
 * @property numeric $vat_amount
 * @property numeric $total
 * @property numeric $self_assessed_vat_amount Mirrors vat_amount for self-assessed regimes; not owed to the vendor
 * @property array<string, mixed>|null $vendor_snapshot Frozen at received
 * @property string|null $note
 * @property string|null $vendor_account_number Domestic format [prefix-]number
 * @property string|null $vendor_bank_code
 * @property string|null $vendor_iban
 * @property string|null $vendor_bic
 * @property string|null $account_source manual|ocr
 * @property Carbon|null $account_verified_at
 * @property string|null $account_verification_result published|unpublished|unreliable
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read Client|null $client
 * @property-read Collection<int, SupplierInvoiceVatLine> $vatLines
 * @property-read int|null $vat_lines_count
 * @property-read InvoiceInboxItem|null $inboxItem
 * @property-read User|null $user
 *
 * @method static Builder<static>|SupplierInvoice draft()
 * @method static SupplierInvoiceFactory factory($count = null, $state = [])
 * @method static Builder<static>|SupplierInvoice forUser($userId = null)
 * @method static Builder<static>|SupplierInvoice newModelQuery()
 * @method static Builder<static>|SupplierInvoice newQuery()
 * @method static Builder<static>|SupplierInvoice onlyTrashed()
 * @method static Builder<static>|SupplierInvoice query()
 * @method static Builder<static>|SupplierInvoice unpaid()
 * @method static Builder<static>|SupplierInvoice withTrashed(bool $withTrashed = true)
 * @method static Builder<static>|SupplierInvoice withoutTrashed()
 * @method static Builder<static>|SupplierInvoice whereClientId($value)
 * @method static Builder<static>|SupplierInvoice whereCreatedAt($value)
 * @method static Builder<static>|SupplierInvoice whereCurrency($value)
 * @method static Builder<static>|SupplierInvoice whereDeletedAt($value)
 * @method static Builder<static>|SupplierInvoice whereDueAt($value)
 * @method static Builder<static>|SupplierInvoice whereExchangeRate($value)
 * @method static Builder<static>|SupplierInvoice whereId($value)
 * @method static Builder<static>|SupplierInvoice whereInternalNumber($value)
 * @method static Builder<static>|SupplierInvoice whereIssuedAt($value)
 * @method static Builder<static>|SupplierInvoice whereNote($value)
 * @method static Builder<static>|SupplierInvoice wherePaidAt($value)
 * @method static Builder<static>|SupplierInvoice whereReceivedAt($value)
 * @method static Builder<static>|SupplierInvoice whereSelfAssessedVatAmount($value)
 * @method static Builder<static>|SupplierInvoice whereStatus($value)
 * @method static Builder<static>|SupplierInvoice whereSubtotal($value)
 * @method static Builder<static>|SupplierInvoice whereSupplierInvoiceNumber($value)
 * @method static Builder<static>|SupplierInvoice whereTaxableSupplyAt($value)
 * @method static Builder<static>|SupplierInvoice whereTotal($value)
 * @method static Builder<static>|SupplierInvoice whereUpdatedAt($value)
 * @method static Builder<static>|SupplierInvoice whereUserId($value)
 * @method static Builder<static>|SupplierInvoice whereVariableSymbol($value)
 * @method static Builder<static>|SupplierInvoice whereVatAmount($value)
 * @method static Builder<static>|SupplierInvoice whereVatRegime($value)
 * @method static Builder<static>|SupplierInvoice whereVendorSnapshot($value)
 *
 * @mixin Eloquent
 */
class SupplierInvoice extends Model
{
    /** @use HasFactory<SupplierInvoiceFactory> */
    use HasFactory;

    use HasUserScope;
    use HasUuids;
    use SoftDeletes;

    // Mirrors the DB default so a freshly created (not yet re-fetched)
    // instance carries it too.
    protected $attributes = [
        'vat_regime' => 'domestic',
    ];

    protected $fillable = [
        'user_id',
        'client_id',
        'internal_number',
        'supplier_invoice_number',
        'variable_symbol',
        'status',
        'vat_regime',
        'issued_at',
        'taxable_supply_at',
        'due_at',
        'received_at',
        'paid_at',
        'currency',
        'exchange_rate',
        'subtotal',
        'vat_amount',
        'total',
        'self_assessed_vat_amount',
        'vendor_snapshot',
        'note',
        'vendor_account_number',
        'vendor_bank_code',
        'vendor_iban',
        'vendor_bic',
        'account_source',
        'account_verified_at',
        'account_verification_result',
    ];

    protected function casts(): array
    {
        return [
            'currency' => Currency::class,
            'vat_regime' => SupplierVatRegime::class,
            'issued_at' => 'date',
            'taxable_supply_at' => 'date',
            'due_at' => 'date',
            'received_at' => 'date',
            'paid_at' => 'date',
            'exchange_rate' => 'decimal:6',
            'subtotal' => 'decimal:2',
            'vat_amount' => 'decimal:2',
            'total' => 'decimal:2',
            'self_assessed_vat_amount' => 'decimal:2',
            'vendor_snapshot' => 'array',
            'account_verified_at' => 'datetime',
        ];
    }

    // ── Scopes ────────────────────────────────────────────────────────────────

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeDraft(Builder $query): Builder
    {
        return $query->where('status', 'draft');
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeUnpaid(Builder $query): Builder
    {
        return $query->whereIn('status', ['received', 'booked']);
    }

    // ── Computed ──────────────────────────────────────────────────────────────

    public function statusEnum(): SupplierInvoiceStatus
    {
        return SupplierInvoiceStatus::from($this->status);
    }

    public function isEditable(): bool
    {
        return $this->statusEnum()->isEditable();
    }

    public function isPaid(): bool
    {
        return $this->status === 'paid';
    }

    /**
     * A payable vendor account is either the domestic pair (number + bank
     * code) or an IBAN.
     */
    public function hasPaymentAccount(): bool
    {
        return $this->hasDomesticVendorAccount()
            || ($this->vendor_iban !== null && $this->vendor_iban !== '');
    }

    public function hasDomesticVendorAccount(): bool
    {
        return $this->vendor_account_number !== null && $this->vendor_account_number !== ''
            && $this->vendor_bank_code !== null && $this->vendor_bank_code !== '';
    }

    /**
     * Recalculate header totals from VAT recap lines. Self-assessed regimes
     * (eu_reverse_charge/import) owe the vendor only the subtotal — the VAT
     * is declared (and, where applicable, deducted) by us instead of being
     * paid out, so it's mirrored into self_assessed_vat_amount rather than
     * added to total.
     */
    public function recalculateTotals(): self
    {
        $subtotal = round((float) $this->vatLines->sum(fn (SupplierInvoiceVatLine $line): float => (float) $line->base), 2);
        $vatAmount = round((float) $this->vatLines->sum(fn (SupplierInvoiceVatLine $line): float => (float) $line->vat_amount), 2);

        $this->subtotal = $subtotal;
        $this->vat_amount = $vatAmount;

        if ($this->vat_regime->isSelfAssessed()) {
            $this->self_assessed_vat_amount = $vatAmount;
            $this->total = $subtotal;
        } else {
            $this->self_assessed_vat_amount = 0;
            $this->total = round($subtotal + $vatAmount, 2);
        }

        return $this;
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
     * @return BelongsTo<Client, $this>
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /**
     * @return HasMany<SupplierInvoiceVatLine, $this>
     */
    /**
     * @return HasMany<SupplierInvoiceVatLine, $this>
     */
    public function vatLines(): HasMany
    {
        return $this->hasMany(SupplierInvoiceVatLine::class)->orderBy('sort_order');
    }

    /**
     * @return HasOne<InvoiceInboxItem, $this>
     */
    /**
     * @return HasOne<InvoiceInboxItem, $this>
     */
    public function inboxItem(): HasOne
    {
        return $this->hasOne(InvoiceInboxItem::class);
    }
}
