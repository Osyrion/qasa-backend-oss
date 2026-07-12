<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Domain\Models;

use App\Modules\Auth\Domain\Models\User;
use App\Modules\Clients\Domain\Models\Client;
use App\Modules\Invoicing\Domain\Enums\QuoteStatus;
use App\Modules\Invoicing\Domain\Services\VatRecapCalculator;
use App\Modules\Orders\Domain\Models\Order;
use App\Modules\Shared\Enums\Currency;
use App\Modules\Shared\Traits\HasUserScope;
use Database\Factories\Modules\Invoicing\Domain\Models\QuoteFactory;
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
 * @property string $quote_number e.g. CP-2026-001
 * @property string $status
 * @property Carbon $issued_at
 * @property Carbon|null $valid_until
 * @property Currency $currency
 * @property numeric|null $discount_percent
 * @property numeric $discount_amount
 * @property numeric $subtotal
 * @property numeric $vat_amount
 * @property numeric $total
 * @property string|null $note
 * @property string|null $note_above
 * @property array<string, mixed>|null $supplier_snapshot Frozen at first draft -> sent transition
 * @property array<string, mixed>|null $client_snapshot Frozen at first draft -> sent transition
 * @property string|null $public_token Grants read-only public access; set exclusively by CreateQuotePublicLinkAction
 * @property Carbon|null $public_first_viewed_at
 * @property int $public_view_count
 * @property Carbon|null $accepted_at
 * @property Carbon|null $rejected_at
 * @property string|null $decision_note
 * @property string|null $decision_ip
 * @property Carbon|null $emailed_at
 * @property string|null $emailed_to
 * @property string|null $converted_invoice_id
 * @property string|null $converted_order_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read Client|null $client
 * @property-read Collection<int, QuoteItem> $items
 * @property-read int|null $items_count
 * @property-read Invoice|null $convertedInvoice
 * @property-read Order|null $convertedOrder
 * @property-read User|null $user
 *
 * @method static QuoteFactory factory($count = null, $state = [])
 * @method static Builder<static>|Quote forUser($userId = null)
 * @method static Builder<static>|Quote newModelQuery()
 * @method static Builder<static>|Quote newQuery()
 * @method static Builder<static>|Quote onlyTrashed()
 * @method static Builder<static>|Quote query()
 * @method static Builder<static>|Quote withTrashed(bool $withTrashed = true)
 * @method static Builder<static>|Quote withoutTrashed()
 *
 * @mixin Eloquent
 */
class Quote extends Model
{
    use HasFactory;
    use HasUserScope;
    use HasUuids;
    use SoftDeletes;

    protected $fillable = [
        'user_id',
        'client_id',
        'quote_number',
        'status',
        'issued_at',
        'valid_until',
        'currency',
        'discount_percent',
        'discount_amount',
        'subtotal',
        'vat_amount',
        'total',
        'note',
        'note_above',
        'supplier_snapshot',
        'client_snapshot',
        'accepted_at',
        'rejected_at',
        'decision_note',
        'decision_ip',
        'emailed_at',
        'emailed_to',
        'converted_invoice_id',
        'converted_order_id',
    ];

    protected function casts(): array
    {
        return [
            'currency' => Currency::class,
            'issued_at' => 'date',
            'valid_until' => 'date',
            'discount_percent' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'subtotal' => 'decimal:2',
            'vat_amount' => 'decimal:2',
            'total' => 'decimal:2',
            'supplier_snapshot' => 'array',
            'client_snapshot' => 'array',
            'public_first_viewed_at' => 'datetime',
            'public_view_count' => 'integer',
            'accepted_at' => 'datetime',
            'rejected_at' => 'datetime',
            'emailed_at' => 'datetime',
        ];
    }

    // ── Computed ──────────────────────────────────────────────────────────────

    public function statusEnum(): QuoteStatus
    {
        return QuoteStatus::from($this->status);
    }

    public function isDraft(): bool
    {
        return $this->status === QuoteStatus::Draft->value;
    }

    public function isSent(): bool
    {
        return $this->status === QuoteStatus::Sent->value;
    }

    public function isEditable(): bool
    {
        return $this->statusEnum()->isEditable();
    }

    public function isExpired(): bool
    {
        return $this->valid_until !== null && $this->valid_until->isPast();
    }

    /**
     * The stored status is never flipped to Expired on a schedule — this
     * computes it on read so a Sent quote past valid_until reads (and
     * guards) as Expired without a daily cron job.
     */
    public function effectiveStatus(): QuoteStatus
    {
        if ($this->statusEnum() === QuoteStatus::Sent && $this->isExpired()) {
            return QuoteStatus::Expired;
        }

        return $this->statusEnum();
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

        return rtrim((string) config('app.frontend_url'), '/').'/q/'.$this->public_token;
    }

    public function isConverted(): bool
    {
        return $this->converted_invoice_id !== null || $this->converted_order_id !== null;
    }

    /**
     * Recalculate totals from items via the shared VAT recap (per-rate
     * buckets, proportional discount). Call after item/discount changes.
     */
    public function recalculateTotals(): self
    {
        $calculator = new VatRecapCalculator;

        $subtotal = $calculator->subtotalForQuote($this);
        $discount = $calculator->discountAmountForQuote($this);
        $vat = $calculator->vatAmountForQuote($this);

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
        return $this->hasMany(QuoteItem::class)->orderBy('sort_order');
    }

    public function convertedInvoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'converted_invoice_id');
    }

    public function convertedOrder(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'converted_order_id');
    }
}
