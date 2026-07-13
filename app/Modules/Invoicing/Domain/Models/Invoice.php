<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Domain\Models;

use App\Modules\Auth\Domain\Models\User;
use App\Modules\Clients\Domain\Models\Client;
use App\Modules\Invoicing\Domain\Enums\InvoiceStatus;
use App\Modules\Invoicing\Domain\Enums\InvoiceType;
use App\Modules\Invoicing\Domain\Enums\ReverseChargeMode;
use App\Modules\Invoicing\Domain\Services\VatRecapCalculator;
use App\Modules\Shared\Enums\Currency;
use App\Modules\Shared\Traits\HasUserScope;
use Database\Factories\Modules\Invoicing\Domain\Models\InvoiceFactory;
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
 * @property string $id
 * @property string $user_id
 * @property string $client_id
 * @property string $invoice_number e.g. FA-2024-001
 * @property InvoiceType $type
 * @property string|null $related_invoice_id Original invoice for credit_note/storno, or the settled proforma for a settlement invoice
 * @property string|null $settled_invoice_id Set on a proforma once settled; points at the ordinary invoice created from it
 * @property InvoiceStatus $status
 * @property Carbon $issued_at
 * @property Carbon|null $taxable_supply_at DUZP
 * @property Carbon $due_at
 * @property string|null $variable_symbol
 * @property string|null $bank_account_id
 * @property array<string, mixed>|null $bank_account_snapshot Frozen at issue
 * @property array<string, mixed>|null $supplier_snapshot Frozen at issue
 * @property array<string, mixed>|null $client_snapshot Frozen at issue
 * @property numeric|null $discount_percent
 * @property numeric $discount_amount
 * @property bool $reverse_charge
 * @property ReverseChargeMode|null $reverse_charge_mode
 * @property Currency $currency
 * @property numeric|null $exchange_rate_snapshot ČNB rate to CZK frozen at issue (non-CZK invoices)
 * @property numeric $subtotal Sum of all items excl. VAT
 * @property numeric $vat_amount Sum of all VAT amounts
 * @property numeric $total subtotal + vat_amount
 * @property string|null $note Printed below the items table
 * @property string|null $note_above Printed above the items table
 * @property string|null $recurring_template_id Template that generated this invoice
 * @property Carbon|null $emailed_at Last time the invoice was queued for email delivery
 * @property string|null $emailed_to Primary recipient of the last email
 * @property list<string>|null $emailed_cc CC recipients of the last email
 * @property Carbon|null $email_failed_at Set when the queued email job permanently failed; cleared on the next send
 * @property Carbon|null $last_reminded_at Last time a payment reminder was sent
 * @property int $reminder_count Number of payment reminders sent
 * @property Carbon|null $overdue_notified_at First time this invoice was detected past due; idempotency marker for the invoice.overdue event
 * @property Carbon|null $reminders_exhausted_notified_at Set once the owner has been notified that auto-reminders hit the limit
 * @property string|null $public_token Grants read-only public access; set exclusively by CreateInvoicePublicLinkAction
 * @property Carbon|null $public_first_viewed_at First time the public page was opened
 * @property int $public_view_count Number of times the public page was opened
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read Client|null $client
 * @property-read Collection<int, InvoiceItem> $items
 * @property-read int|null $items_count
 * @property-read Collection<int, InvoicePayment> $payments
 * @property-read int|null $payments_count
 * @property-read User|null $user
 *
 * @method static Builder<static>|Invoice draft()
 * @method static InvoiceFactory factory($count = null, $state = [])
 * @method static Builder<static>|Invoice forUser($userId = null)
 * @method static Builder<static>|Invoice newModelQuery()
 * @method static Builder<static>|Invoice newQuery()
 * @method static Builder<static>|Invoice onlyTrashed()
 * @method static Builder<static>|Invoice overdue()
 * @method static Builder<static>|Invoice paid()
 * @method static Builder<static>|Invoice query()
 * @method static Builder<static>|Invoice sent()
 * @method static Builder<static>|Invoice whereClientId($value)
 * @method static Builder<static>|Invoice whereCreatedAt($value)
 * @method static Builder<static>|Invoice whereCurrency($value)
 * @method static Builder<static>|Invoice whereDeletedAt($value)
 * @method static Builder<static>|Invoice whereDueAt($value)
 * @method static Builder<static>|Invoice whereExchangeRateSnapshot($value)
 * @method static Builder<static>|Invoice whereId($value)
 * @method static Builder<static>|Invoice whereInvoiceNumber($value)
 * @method static Builder<static>|Invoice whereIssuedAt($value)
 * @method static Builder<static>|Invoice whereNote($value)
 * @method static Builder<static>|Invoice whereStatus($value)
 * @method static Builder<static>|Invoice whereSubtotal($value)
 * @method static Builder<static>|Invoice whereTotal($value)
 * @method static Builder<static>|Invoice whereUpdatedAt($value)
 * @method static Builder<static>|Invoice whereUserId($value)
 * @method static Builder<static>|Invoice whereVatAmount($value)
 * @method static Builder<static>|Invoice withTrashed(bool $withTrashed = true)
 * @method static Builder<static>|Invoice withoutTrashed()
 *
 * @mixin Eloquent
 */
class Invoice extends Model
{
    use HasFactory;
    use HasUserScope;
    use HasUuids;
    use SoftDeletes;

    protected $fillable = [
        'user_id',
        'client_id',
        'invoice_number',
        'type',
        'related_invoice_id',
        'settled_invoice_id',
        'status',
        'issued_at',
        'taxable_supply_at',
        'due_at',
        'variable_symbol',
        'bank_account_id',
        'bank_account_snapshot',
        'supplier_snapshot',
        'client_snapshot',
        'currency',
        'exchange_rate_snapshot',
        'subtotal',
        'discount_percent',
        'discount_amount',
        'reverse_charge',
        'reverse_charge_mode',
        'vat_amount',
        'total',
        'note',
        'note_above',
        'recurring_template_id',
        'emailed_at',
        'emailed_to',
        'emailed_cc',
        'email_failed_at',
        'last_reminded_at',
        'reminder_count',
        'overdue_notified_at',
        'reminders_exhausted_notified_at',
    ];

    protected function casts(): array
    {
        return [
            'currency' => Currency::class,
            'type' => InvoiceType::class,
            'status' => InvoiceStatus::class,
            'issued_at' => 'date',
            'taxable_supply_at' => 'date',
            'due_at' => 'date',
            'bank_account_snapshot' => 'array',
            'supplier_snapshot' => 'array',
            'client_snapshot' => 'array',
            'exchange_rate_snapshot' => 'decimal:6',
            'subtotal' => 'decimal:2',
            'discount_percent' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'reverse_charge' => 'boolean',
            'reverse_charge_mode' => ReverseChargeMode::class,
            'vat_amount' => 'decimal:2',
            'total' => 'decimal:2',
            'emailed_at' => 'datetime',
            'emailed_cc' => 'array',
            'email_failed_at' => 'datetime',
            'last_reminded_at' => 'datetime',
            'overdue_notified_at' => 'datetime',
            'reminders_exhausted_notified_at' => 'datetime',
            'public_first_viewed_at' => 'datetime',
            'public_view_count' => 'integer',
        ];
    }

    // ── Scopes ────────────────────────────────────────────────────────────────

    public function scopeDraft($query)
    {
        return $query->where('status', InvoiceStatus::Draft->value);
    }

    public function scopeSent($query)
    {
        return $query->where('status', InvoiceStatus::Sent->value);
    }

    public function scopePaid($query)
    {
        return $query->where('status', InvoiceStatus::Paid->value);
    }

    public function scopeOverdue($query)
    {
        return $query->where('status', InvoiceStatus::Sent->value)
            ->where('due_at', '<', now()->toDateString());
    }

    // ── Computed ──────────────────────────────────────────────────────────────

    public function isDraft(): bool
    {
        return $this->status === InvoiceStatus::Draft;
    }

    public function isSent(): bool
    {
        return $this->status === InvoiceStatus::Sent;
    }

    public function isPaid(): bool
    {
        return $this->status === InvoiceStatus::Paid;
    }

    public function isCancelled(): bool
    {
        return $this->status === InvoiceStatus::Cancelled;
    }

    public function isCreditNote(): bool
    {
        return $this->type === InvoiceType::CreditNote;
    }

    public function isProforma(): bool
    {
        return $this->type === InvoiceType::Proforma;
    }

    public function isSettled(): bool
    {
        return $this->settled_invoice_id !== null;
    }

    public function statusEnum(): InvoiceStatus
    {
        return $this->status;
    }

    /**
     * Outstanding amount in the invoice currency — total minus recorded
     * payments. Negative once overpaid.
     */
    public function balance(): float
    {
        return round((float) $this->total - (float) $this->payments()->sum('amount'), 2);
    }

    public function isOverdue(): bool
    {
        return $this->isSent() && $this->due_at->isPast();
    }

    public function isEditable(): bool
    {
        return $this->isDraft();
    }

    public function hasPublicLink(): bool
    {
        return $this->public_token !== null;
    }

    public function publicUrl(): ?string
    {
        if ($this->public_token === null) {
            return null;
        }

        return rtrim((string) config('app.frontend_url'), '/').'/i/'.$this->public_token;
    }

    public function daysUntilDue(): int
    {
        return (int) now()->diffInDays($this->due_at, false);
    }

    /**
     * A reverse-charged invoice carries no VAT — every item's rate is forced
     * to 0% (domestic and EU reverse charge alike). Call before
     * recalculateTotals() whenever reverse_charge flips on or an item is
     * added to an already-reverse-charged invoice.
     */
    public function normalizeItemsForReverseCharge(): void
    {
        $this->loadMissing('items');

        foreach ($this->items as $item) {
            if ((float) $item->vat_rate === 0.0) {
                continue;
            }

            $item->vat_rate = 0;
            $item->recalculate();
            $item->save();
        }
    }

    /**
     * Recalculate totals from items via the VAT recap (per-rate buckets,
     * proportional invoice-level discount). Call after item/discount changes.
     */
    public function recalculateTotals(): self
    {
        $calculator = new VatRecapCalculator;

        $subtotal = $calculator->subtotal($this);
        $discount = $calculator->discountAmount($this);
        $vat = $calculator->vatAmount($this);

        $this->subtotal = $subtotal;
        $this->discount_amount = $discount;
        $this->vat_amount = $vat;
        $this->total = round($subtotal - $discount + $vat, 2);

        return $this;
    }

    // ── Relations ─────────────────────────────────────────────────────────────

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(InvoiceItem::class)->orderBy('sort_order');
    }

    /**
     * @return HasMany<InvoicePayment, $this>
     */
    public function payments(): HasMany
    {
        return $this->hasMany(InvoicePayment::class)->orderBy('paid_at');
    }

    /**
     * @return BelongsTo<BankAccount, $this>
     */
    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class);
    }

    /**
     * @return BelongsTo<Invoice, $this>
     */
    public function relatedInvoice(): BelongsTo
    {
        return $this->belongsTo(self::class, 'related_invoice_id');
    }

    /**
     * @return BelongsTo<Invoice, $this>
     */
    public function settledInvoice(): BelongsTo
    {
        return $this->belongsTo(self::class, 'settled_invoice_id');
    }

    /**
     * @return HasMany<InvoiceWorkReportLine, $this>
     */
    public function workReportLines(): HasMany
    {
        return $this->hasMany(InvoiceWorkReportLine::class)->orderBy('sort_order');
    }

    /**
     * @return BelongsTo<RecurringInvoiceTemplate, $this>
     */
    public function recurringTemplate(): BelongsTo
    {
        return $this->belongsTo(RecurringInvoiceTemplate::class);
    }
}
