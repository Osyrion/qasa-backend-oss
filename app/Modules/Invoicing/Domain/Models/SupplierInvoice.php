<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Domain\Models;

use App\Modules\Auth\Domain\Models\User;
use App\Modules\Clients\Domain\Models\Client;
use App\Modules\Invoicing\Domain\Enums\SupplierInvoiceStatus;
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
 * @property array<string, mixed>|null $vendor_snapshot Frozen at received
 * @property string|null $note
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
 *
 * @mixin Eloquent
 */
class SupplierInvoice extends Model
{
    use HasFactory;
    use HasUserScope;
    use HasUuids;
    use SoftDeletes;

    protected $fillable = [
        'user_id',
        'client_id',
        'internal_number',
        'supplier_invoice_number',
        'variable_symbol',
        'status',
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
        'vendor_snapshot',
        'note',
    ];

    protected function casts(): array
    {
        return [
            'currency' => Currency::class,
            'issued_at' => 'date',
            'taxable_supply_at' => 'date',
            'due_at' => 'date',
            'received_at' => 'date',
            'paid_at' => 'date',
            'exchange_rate' => 'decimal:6',
            'subtotal' => 'decimal:2',
            'vat_amount' => 'decimal:2',
            'total' => 'decimal:2',
            'vendor_snapshot' => 'array',
        ];
    }

    // ── Scopes ────────────────────────────────────────────────────────────────

    public function scopeDraft($query)
    {
        return $query->where('status', 'draft');
    }

    public function scopeUnpaid($query)
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
     * Recalculate header totals from VAT recap lines.
     */
    public function recalculateTotals(): self
    {
        $subtotal = round((float) $this->vatLines->sum(fn (SupplierInvoiceVatLine $line): float => (float) $line->base), 2);
        $vatAmount = round((float) $this->vatLines->sum(fn (SupplierInvoiceVatLine $line): float => (float) $line->vat_amount), 2);

        $this->subtotal = $subtotal;
        $this->vat_amount = $vatAmount;
        $this->total = round($subtotal + $vatAmount, 2);

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
    public function inboxItem(): HasOne
    {
        return $this->hasOne(InvoiceInboxItem::class);
    }
}
