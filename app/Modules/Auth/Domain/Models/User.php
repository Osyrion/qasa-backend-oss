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
use App\Modules\Shared\Enums\Currency;
use App\Modules\Shared\Enums\VatStatus;
use Database\Factories\Modules\Auth\Domain\Models\UserFactory;
use Eloquent;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Notifications\DatabaseNotificationCollection;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\HasApiTokens;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Core single-account user. The concrete class is resolved through the
 * auth provider config, so a subclass can be swapped in without touching
 * call sites.
 *
 * @property string $id
 * @property string|null $title
 * @property string $name
 * @property string $surname
 * @property string $email
 * @property string|null $phone
 * @property string|null $password Null if Google auth only
 * @property string|null $google_id
 * @property string|null $avatar_path
 * @property string|null $color Hex, e.g. #3B82F6
 * @property string|null $ico
 * @property string|null $dic
 * @property bool $is_vat_payer Deprecated, kept in sync with vat_status; vat_status is the source of truth
 * @property VatStatus $vat_status
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
 * @property string $locale UI language
 * @property string $country ISO 3166-1 alpha-2
 * @property string|null $address
 * @property string|null $city
 * @property string|null $postal_code
 * @property string|null $logo_path Supplier logo printed on invoices
 * @property string|null $vat_id IČ DPH / VAT ID
 * @property string|null $website
 * @property string|null $invoice_footer_text
 * @property int $overdue_reminder_days Dashboard overdue-reminder threshold in days
 * @property bool $auto_remind_enabled Whether overdue invoices get automatic reminder emails
 * @property int $auto_remind_max Max automatic reminders sent per invoice, 1-10
 * @property int $auto_remind_interval_days Minimum days between automatic reminders
 * @property bool $overdue_digest_enabled Whether the owner receives a daily digest of invoices newly past due
 * @property Carbon|null $vat_status_confirmed_at Set when the user explicitly saves vat_status/is_vat_payer via the profile — drives the onboarding setup checklist, since vat_status itself always has a DB default
 * @property string|null $two_factor_secret Base32 TOTP secret; unconfirmed until two_factor_confirmed_at is set
 * @property list<string>|null $two_factor_recovery_codes Hashed one-time recovery codes
 * @property Carbon|null $two_factor_confirmed_at
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
 * @property-read DatabaseNotificationCollection<int, DatabaseNotification> $notifications
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
 * @method static Builder<static>|User whereAutoRemindEnabled($value)
 * @method static Builder<static>|User whereAutoRemindIntervalDays($value)
 * @method static Builder<static>|User whereAutoRemindMax($value)
 * @method static Builder<static>|User whereInvoiceFooterText($value)
 * @method static Builder<static>|User whereInvoiceInboxEnabled($value)
 * @method static Builder<static>|User whereInvoiceNumberMask($value)
 * @method static Builder<static>|User whereInvoiceNumberStart($value)
 * @method static Builder<static>|User whereLogoPath($value)
 * @method static Builder<static>|User whereOverdueDigestEnabled($value)
 * @method static Builder<static>|User whereOverdueReminderDays($value)
 * @method static Builder<static>|User whereQuoteNumberMask($value)
 * @method static Builder<static>|User whereQuoteNumberStart($value)
 * @method static Builder<static>|User whereSupplierInvoiceNumberMask($value)
 * @method static Builder<static>|User whereSupplierInvoiceNumberStart($value)
 * @method static Builder<static>|User whereTwoFactorConfirmedAt($value)
 * @method static Builder<static>|User whereTwoFactorRecoveryCodes($value)
 * @method static Builder<static>|User whereTwoFactorSecret($value)
 * @method static Builder<static>|User whereVatId($value)
 * @method static Builder<static>|User whereVatStatus($value)
 * @method static Builder<static>|User whereVatStatusConfirmedAt($value)
 * @method static Builder<static>|User whereWebsite($value)
 *
 * @mixin Eloquent
 */
class User extends Authenticatable implements MustVerifyEmail, ProvidesAccountMeta
{
    use HasApiTokens;

    /** @use HasFactory<UserFactory> */
    use HasFactory;

    use HasUuids;
    use \Illuminate\Auth\MustVerifyEmail;
    use Notifiable;
    use SoftDeletes;

    protected $fillable = [
        'title', 'name', 'surname', 'email', 'phone',
        'password', 'google_id', 'avatar_path', 'color',
        'ico', 'dic', 'is_vat_payer', 'vat_status', 'vat_status_confirmed_at', 'tax_flat_rate',
        'default_currency', 'invoice_prefix', 'invoice_number_mask', 'invoice_number_start', 'locale',
        'supplier_invoice_number_mask', 'supplier_invoice_number_start', 'invoice_inbox_enabled',
        'quote_number_mask', 'quote_number_start',
        'country', 'address', 'city', 'postal_code',
        'logo_path', 'vat_id', 'website', 'invoice_footer_text',
        'overdue_reminder_days',
        'auto_remind_enabled', 'auto_remind_max', 'auto_remind_interval_days',
        'overdue_digest_enabled',
        'two_factor_secret', 'two_factor_recovery_codes', 'two_factor_confirmed_at',
    ];

    protected $hidden = [
        'password', 'remember_token', 'google_id',
        'two_factor_secret', 'two_factor_recovery_codes',
    ];

    // Mirrors the DB defaults so freshly created (not yet re-fetched)
    // instances carry them too.
    protected $attributes = [
        'overdue_reminder_days' => 14,
        'vat_status' => 'non_payer',
        'auto_remind_enabled' => false,
        'auto_remind_max' => 3,
        'auto_remind_interval_days' => 7,
        'overdue_digest_enabled' => true,
    ];

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
            'invoice_inbox_enabled' => 'boolean',
            'overdue_reminder_days' => 'integer',
            'auto_remind_enabled' => 'boolean',
            'auto_remind_max' => 'integer',
            'auto_remind_interval_days' => 'integer',
            'overdue_digest_enabled' => 'boolean',
            'default_currency' => Currency::class,
            'two_factor_secret' => 'encrypted',
            'two_factor_recovery_codes' => 'encrypted:array',
            'two_factor_confirmed_at' => 'datetime',
        ];
    }

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
        });
    }

    // ── Computed ──────────────────────────────────────────────────────────────

    public function getFullNameAttribute(): string
    {
        return trim(implode(' ', array_filter([
            $this->title, $this->name, $this->surname,
        ])));
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

    public function hasTwoFactorEnabled(): bool
    {
        return $this->two_factor_confirmed_at !== null;
    }

    /**
     * @phpstan-assert-if-true !null $this->password
     */
    public function hasPassword(): bool
    {
        return $this->password !== null;
    }

    // ── Account ───────────────────────────────────────────────────────────────

    /**
     * All business data is keyed by the account owner's user_id. The core
     * edition is single-account — every user owns their own data; subclasses
     * may override this to point at a different owning account.
     */
    public function accountOwnerId(): string
    {
        return $this->id;
    }

    public function accountOwner(): self
    {
        return $this;
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

    // ── Relations ─────────────────────────────────────────────────────────────

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
