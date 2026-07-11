<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Domain\Models;

use App\Modules\Auth\Domain\Models\User;
use App\Modules\Clients\Domain\Models\Client;
use App\Modules\Invoicing\Domain\Enums\InvoiceType;
use App\Modules\Invoicing\Domain\Enums\RecurringPeriod;
use App\Modules\Invoicing\Domain\Enums\RecurringTemplateStatus;
use App\Modules\Invoicing\Domain\Enums\TaxDateMode;
use App\Modules\Shared\Enums\Currency;
use App\Modules\Shared\Traits\HasUserScope;
use Carbon\CarbonImmutable;
use Database\Factories\Modules\Invoicing\Domain\Models\RecurringInvoiceTemplateFactory;
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
 * @property string $name
 * @property RecurringTemplateStatus $status
 * @property RecurringPeriod $period
 * @property int $day_of_month 1-28, ignored when last_day_of_month
 * @property bool $last_day_of_month
 * @property CarbonImmutable $first_issue_date
 * @property CarbonImmutable|null $end_date Template expires once next_run_date passes it
 * @property CarbonImmutable $next_run_date
 * @property CarbonImmutable|null $last_generated_at issued_at of the last generated invoice
 * @property InvoiceType $type invoice|proforma
 * @property Currency $currency
 * @property int $due_days
 * @property numeric|null $discount_percent
 * @property TaxDateMode $tax_date_mode
 * @property bool $auto_send Issue and email generated invoices automatically
 * @property string|null $note_above
 * @property string|null $note_below Copied to invoices.note
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read Client|null $client
 * @property-read Collection<int, RecurringInvoiceTemplateItem> $items
 * @property-read int|null $items_count
 * @property-read Collection<int, Invoice> $invoices
 * @property-read int|null $invoices_count
 * @property-read User|null $user
 *
 * @method static Builder<static>|RecurringInvoiceTemplate dueForGeneration(CarbonImmutable $today)
 * @method static RecurringInvoiceTemplateFactory factory($count = null, $state = [])
 * @method static Builder<static>|RecurringInvoiceTemplate forUser($userId = null)
 * @method static Builder<static>|RecurringInvoiceTemplate newModelQuery()
 * @method static Builder<static>|RecurringInvoiceTemplate newQuery()
 * @method static Builder<static>|RecurringInvoiceTemplate onlyTrashed()
 * @method static Builder<static>|RecurringInvoiceTemplate query()
 * @method static Builder<static>|RecurringInvoiceTemplate withTrashed(bool $withTrashed = true)
 * @method static Builder<static>|RecurringInvoiceTemplate withoutTrashed()
 *
 * @mixin Eloquent
 */
class RecurringInvoiceTemplate extends Model
{
    /** @use HasFactory<RecurringInvoiceTemplateFactory> */
    use HasFactory;

    use HasUserScope;
    use HasUuids;
    use SoftDeletes;

    protected $fillable = [
        'user_id',
        'client_id',
        'name',
        'status',
        'period',
        'day_of_month',
        'last_day_of_month',
        'first_issue_date',
        'end_date',
        'next_run_date',
        'last_generated_at',
        'type',
        'currency',
        'due_days',
        'discount_percent',
        'tax_date_mode',
        'auto_send',
        'note_above',
        'note_below',
    ];

    protected function casts(): array
    {
        return [
            'status' => RecurringTemplateStatus::class,
            'period' => RecurringPeriod::class,
            'type' => InvoiceType::class,
            'currency' => Currency::class,
            'tax_date_mode' => TaxDateMode::class,
            'day_of_month' => 'integer',
            'last_day_of_month' => 'boolean',
            'first_issue_date' => 'immutable_date',
            'end_date' => 'immutable_date',
            'next_run_date' => 'immutable_date',
            'last_generated_at' => 'immutable_date',
            'due_days' => 'integer',
            'discount_percent' => 'decimal:2',
            'auto_send' => 'boolean',
        ];
    }

    // ── Scopes ────────────────────────────────────────────────────────────────

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeDueForGeneration(Builder $query, CarbonImmutable $today): Builder
    {
        return $query
            ->where('status', RecurringTemplateStatus::Active->value)
            // whereDate: compare by calendar date regardless of any time
            // component stored on the column.
            ->whereDate('next_run_date', '<=', $today->toDateString());
    }

    // ── Computed ──────────────────────────────────────────────────────────────

    public function isActive(): bool
    {
        return $this->status === RecurringTemplateStatus::Active;
    }

    public function isPaused(): bool
    {
        return $this->status === RecurringTemplateStatus::Paused;
    }

    public function isExpired(): bool
    {
        return $this->status === RecurringTemplateStatus::Expired;
    }

    /**
     * Move next_run_date to the following occurrence; expire the template
     * once it passes end_date. Single home for the advance+expire rule.
     */
    public function advanceSchedule(): self
    {
        $this->next_run_date = $this->period->nextDate(
            $this->next_run_date,
            $this->day_of_month,
            $this->last_day_of_month,
        );

        if ($this->end_date !== null && $this->next_run_date->greaterThan($this->end_date)) {
            $this->status = RecurringTemplateStatus::Expired;
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
     * @return HasMany<RecurringInvoiceTemplateItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(RecurringInvoiceTemplateItem::class, 'template_id')->orderBy('sort_order');
    }

    /**
     * @return HasMany<Invoice, $this>
     */
    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class, 'recurring_template_id');
    }
}
