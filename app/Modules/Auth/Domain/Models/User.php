<?php

declare(strict_types=1);

namespace App\Modules\Auth\Domain\Models;

use App\Modules\Auth\Domain\Contracts\ProvidesAccountMeta;
use App\Modules\Clients\Domain\Models\Client;
use App\Modules\Invoicing\Domain\Models\ExchangeRate;
use App\Modules\Invoicing\Domain\Models\Expense;
use App\Modules\Invoicing\Domain\Models\Invoice;
use App\Modules\Orders\Domain\Models\Order;
use App\Modules\Orders\Domain\Models\OrderAttachment;
use App\Modules\Orders\Domain\Models\OrderNote;
use App\Modules\Shared\Authorization\AbilityCatalog;
use App\Modules\Shared\Domain\Models\AccountNotification;
use App\Modules\Shared\Enums\Currency;
use App\Modules\Shared\Enums\VatStatus;
use App\Modules\Shared\Exceptions\DomainException;
use App\Modules\Taxation\Domain\Enums\VatFilingFrequency;
use Database\Factories\Modules\Auth\Domain\Models\UserFactory;
use Eloquent;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\DatabaseNotificationCollection;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\HasApiTokens;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Core single-account user. The SaaS edition extends this model with
 * billing, roles and teams and swaps it in via the auth provider config.
 *
 * @property string $id
 * @property string|null $title
 * @property string $name
 * @property string $surname
 * @property string $email
 * @property string|null $phone
 * @property string|null $password Null if Google auth only
 * @property string|null $google_id
 * @property string|null $two_factor_secret Base32 TOTP secret; unconfirmed until two_factor_confirmed_at is set
 * @property list<string>|null $two_factor_recovery_codes Hashed one-time recovery codes
 * @property Carbon|null $two_factor_confirmed_at
 * @property string|null $avatar_path
 * @property string|null $color Hex, e.g. #3B82F6
 * @property string|null $ico
 * @property string|null $dic
 * @property bool $is_vat_payer Deprecated, kept in sync with vat_status; vat_status is the source of truth
 * @property VatStatus $vat_status
 * @property Carbon|null $vat_status_confirmed_at Set when the user explicitly saves vat_status/is_vat_payer via the profile — drives the onboarding setup checklist, since vat_status itself always has a DB default
 * @property int $tax_flat_rate 0-80; 0 = real expenses
 * @property Currency $default_currency
 * @property string $invoice_prefix
 * @property string|null $invoice_number_mask
 * @property int|null $invoice_number_start
 * @property string|null $supplier_invoice_number_mask
 * @property int|null $supplier_invoice_number_start
 * @property string|null $quote_number_mask
 * @property int|null $quote_number_start
 * @property bool $invoice_inbox_enabled
 * @property bool $auto_remind_enabled
 * @property int $auto_remind_after_days Days after due_at before the first automatic reminder
 * @property int $auto_remind_max_count Cap on total reminders (manual + automatic) sent for one invoice
 * @property bool $overdue_digest_enabled Owner notification when an invoice newly crosses into overdue — distinct from auto_remind_enabled, which emails the client
 * @property VatFilingFrequency|null $vat_filing_frequency How often this VAT payer files a return — null until set via profile, drives the TaxFiling archive/reminder period
 * @property bool $tax_filing_reminder_enabled Opt-in for the VAT filing deadline reminder (Automation module)
 * @property string $locale UI language
 * @property string|null $country ISO 3166-1 alpha-2 — SK/CZ only; null until complete-residency (step 2), then immutable
 * @property string|null $company_name Sole source of the supplier's printed name once set — see supplierName()
 * @property string|null $address
 * @property string|null $city
 * @property string|null $postal_code
 * @property string|null $logo_path Supplier logo printed on invoices
 * @property string|null $vat_id IČ DPH / VAT ID
 * @property string|null $website
 * @property string|null $invoice_footer_text
 * @property string|null $clockify_api_key
 * @property string|null $clockify_workspace_id
 * @property bool $ai_extraction_enabled Account-wide switch for AI invoice extraction (BYOK or platform) — the account may still fall back to regex per FieldExtractorFactory's decision tree
 * @property Carbon|null $email_verified_at
 * @property string|null $remember_token
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read Collection<int, Client> $clients
 * @property-read int|null $clients_count
 * @property-read Collection<int, ExchangeRate> $exchangeRates
 * @property-read int|null $exchange_rates_count
 * @property-read Collection<int, Expense> $expenses
 * @property-read int|null $expenses_count
 * @property-read string $full_name
 * @property-read Collection<int, Invoice> $invoices
 * @property-read int|null $invoices_count
 * @property-read DatabaseNotificationCollection<int, AccountNotification> $notifications
 * @property-read int|null $notifications_count
 * @property-read Collection<int, OrderAttachment> $orderAttachments
 * @property-read int|null $order_attachments_count
 * @property-read Collection<int, OrderNote> $orderNotes
 * @property-read int|null $order_notes_count
 * @property-read Collection<int, Order> $orders
 * @property-read int|null $orders_count
 * @property-read Collection<int, PersonalAccessToken> $tokens
 * @property-read int|null $tokens_count
 *
 * @method static UserFactory factory($count = null, $state = [])
 * @method static Builder<static>|User newModelQuery()
 * @method static Builder<static>|User newQuery()
 * @method static Builder<static>|User onlyTrashed()
 * @method static Builder<static>|User query()
 * @method static Builder<static>|User whereAddress($value)
 * @method static Builder<static>|User whereAvatarPath($value)
 * @method static Builder<static>|User whereCity($value)
 * @method static Builder<static>|User whereColor($value)
 * @method static Builder<static>|User whereCountry($value)
 * @method static Builder<static>|User whereCreatedAt($value)
 * @method static Builder<static>|User whereDefaultCurrency($value)
 * @method static Builder<static>|User whereDeletedAt($value)
 * @method static Builder<static>|User whereDic($value)
 * @method static Builder<static>|User whereEmail($value)
 * @method static Builder<static>|User whereEmailVerifiedAt($value)
 * @method static Builder<static>|User whereGoogleId($value)
 * @method static Builder<static>|User whereIco($value)
 * @method static Builder<static>|User whereId($value)
 * @method static Builder<static>|User whereInvoicePrefix($value)
 * @method static Builder<static>|User whereIsVatPayer($value)
 * @method static Builder<static>|User whereLocale($value)
 * @method static Builder<static>|User whereName($value)
 * @method static Builder<static>|User wherePassword($value)
 * @method static Builder<static>|User wherePhone($value)
 * @method static Builder<static>|User wherePostalCode($value)
 * @method static Builder<static>|User whereRememberToken($value)
 * @method static Builder<static>|User whereSurname($value)
 * @method static Builder<static>|User whereTaxFlatRate($value)
 * @method static Builder<static>|User whereTitle($value)
 * @method static Builder<static>|User whereUpdatedAt($value)
 * @method static Builder<static>|User withTrashed(bool $withTrashed = true)
 * @method static Builder<static>|User withoutTrashed()
 *
 * @mixin Eloquent
 */
class User extends Authenticatable implements MustVerifyEmail, ProvidesAccountMeta
{
    use HasApiTokens;

    /** @use HasFactory<UserFactory> */
    use HasFactory;

    use HasUuids;
    use Notifiable;
    use SoftDeletes;

    protected $fillable = [
        'title', 'name', 'surname', 'email', 'phone',
        'password', 'google_id', 'avatar_path', 'color',
        'two_factor_secret', 'two_factor_recovery_codes', 'two_factor_confirmed_at',
        'ico', 'dic', 'is_vat_payer', 'vat_status', 'vat_status_confirmed_at', 'tax_flat_rate',
        'default_currency', 'invoice_prefix', 'invoice_number_mask', 'invoice_number_start', 'locale',
        'supplier_invoice_number_mask', 'supplier_invoice_number_start',
        'quote_number_mask', 'quote_number_start', 'invoice_inbox_enabled',
        'auto_remind_enabled', 'auto_remind_after_days', 'auto_remind_max_count', 'overdue_digest_enabled',
        'vat_filing_frequency', 'tax_filing_reminder_enabled',
        'country', 'company_name', 'address', 'city', 'postal_code',
        'logo_path', 'vat_id', 'website', 'invoice_footer_text',
        'clockify_api_key', 'clockify_workspace_id', 'ai_extraction_enabled',
    ];

    protected $hidden = [
        'password', 'remember_token', 'google_id', 'clockify_api_key',
        'two_factor_secret', 'two_factor_recovery_codes',
    ];

    // Mirrors the DB defaults so freshly created (not yet re-fetched)
    // instances carry them too.
    protected $attributes = [
        'vat_status' => 'non_payer',
        'overdue_digest_enabled' => true,
        'ai_extraction_enabled' => false,
    ];

    /**
     * is_vat_payer is deprecated but still read by old snapshots;
     * keep it mirroring vat_status until it's dropped.
     */
    protected static function booted(): void
    {
        static::saving(function (self $user): void {
            if ($user->isDirty('vat_status')) {
                $user->is_vat_payer = $user->vat_status === VatStatus::Payer;
            }

            // Tax residency is set once (complete-residency) and never
            // changes after — the model is the last line of defense, since
            // UpdateProfileData/Action never expose "country" at all.
            if ($user->isDirty('country') && $user->getOriginal('country') !== null) {
                throw DomainException::because(__('taxation.residency_locked'));
            }
        });
    }

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_vat_payer' => 'boolean',
            'vat_status' => VatStatus::class,
            'vat_status_confirmed_at' => 'datetime',
            'tax_flat_rate' => 'integer',
            'invoice_number_start' => 'integer',
            'supplier_invoice_number_start' => 'integer',
            'quote_number_start' => 'integer',
            'invoice_inbox_enabled' => 'boolean',
            'auto_remind_enabled' => 'boolean',
            'auto_remind_after_days' => 'integer',
            'auto_remind_max_count' => 'integer',
            'overdue_digest_enabled' => 'boolean',
            'vat_filing_frequency' => VatFilingFrequency::class,
            'tax_filing_reminder_enabled' => 'boolean',
            'default_currency' => Currency::class,
            'clockify_api_key' => 'encrypted',
            'ai_extraction_enabled' => 'boolean',
            'two_factor_secret' => 'encrypted',
            'two_factor_recovery_codes' => 'encrypted:array',
            'two_factor_confirmed_at' => 'datetime',
        ];
    }

    // ── Computed ──────────────────────────────────────────────────────────────

    public function getFullNameAttribute(): string
    {
        return trim(implode(' ', array_filter([
            $this->title, $this->name, $this->surname,
        ])));
    }

    /**
     * The supplier name printed on every document (PDF, exports, statements)
     * — company_name is the sole source once set; full_name is the only
     * allowed fallback (accounts before complete-residency have no
     * company_name yet).
     */
    public function supplierName(): string
    {
        return $this->company_name ?? $this->full_name;
    }

    public function hasTaxResidency(): bool
    {
        return $this->country !== null;
    }

    public function usesRealExpenses(): bool
    {
        return $this->tax_flat_rate === 0;
    }

    public function usesFlatRate(): bool
    {
        return $this->tax_flat_rate > 0;
    }

    public function hasGoogleAuth(): bool
    {
        return $this->google_id !== null;
    }

    public function hasPassword(): bool
    {
        return $this->password !== null;
    }

    /**
     * 2FA is only active once a code has been confirmed against the secret —
     * an unconfirmed secret (setup started but never completed) does not count.
     */
    public function hasTwoFactorEnabled(): bool
    {
        return $this->two_factor_confirmed_at !== null;
    }

    // ── Account ───────────────────────────────────────────────────────────────

    /**
     * All business data is keyed by the account owner's user_id. The core
     * edition is single-account — every user owns their own data; the SaaS
     * edition overrides this for team members.
     */
    public function accountOwnerId(): string
    {
        return $this->id;
    }

    public function accountOwner(): self
    {
        return $this;
    }

    /**
     * Plan-limit hook. The core (OSS) edition has no plans — never limited.
     * The SaaS user model overrides this against the active subscription.
     */
    public function withinLimit(string $limitKey, int $currentCount): bool
    {
        return true;
    }

    /**
     * Plan feature-gate hook. The core (OSS) edition has no plans — every
     * feature is available. The SaaS user model overrides this against the
     * active subscription's features.
     */
    public function hasFeature(string $feature): bool
    {
        return true;
    }

    // ── ProvidesAccountMeta (single-account defaults) ─────────────────────────

    public function roleName(): ?string
    {
        return 'owner';
    }

    public function permissionNames(): array
    {
        return AbilityCatalog::abilities();
    }

    public function isTeamMember(): bool
    {
        return false;
    }

    public function accountOwnerMeta(): ?array
    {
        return null;
    }

    public function exposesPlan(): bool
    {
        return false;
    }

    public function planSlug(): ?string
    {
        return null;
    }

    /**
     * The core edition has no plans, so it has no trial either.
     */
    public function trialMeta(): ?array
    {
        return null;
    }

    // ── Relations ─────────────────────────────────────────────────────────────

    /**
     * In-app notifications addressed to this user.
     *
     * Overrides HasDatabaseNotifications so the rows come back as
     * AccountNotification — the tenant-scoped model — rather than the
     * framework's own. readNotifications()/unreadNotifications() are defined
     * in terms of this method, so they follow.
     *
     * @return MorphMany<AccountNotification, $this>
     */
    public function notifications(): MorphMany
    {
        return $this->morphMany(AccountNotification::class, 'notifiable')->latest();
    }

    /**
     * @return HasMany<Client, $this>
     */
    public function clients(): HasMany
    {
        return $this->hasMany(Client::class);
    }

    /**
     * @return HasMany<Order, $this>
     */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    /**
     * @return HasMany<OrderNote, $this>
     */
    public function orderNotes(): HasMany
    {
        return $this->hasMany(OrderNote::class);
    }

    /**
     * @return HasMany<OrderAttachment, $this>
     */
    public function orderAttachments(): HasMany
    {
        return $this->hasMany(OrderAttachment::class);
    }

    /**
     * @return HasMany<Expense, $this>
     */
    public function expenses(): HasMany
    {
        return $this->hasMany(Expense::class);
    }

    /**
     * @return HasMany<ExchangeRate, $this>
     */
    public function exchangeRates(): HasMany
    {
        return $this->hasMany(ExchangeRate::class);
    }

    /**
     * @return HasMany<Invoice, $this>
     */
    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }
}
