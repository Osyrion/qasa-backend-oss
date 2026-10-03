<?php

declare(strict_types=1);

namespace App\Modules\Auth\Domain\Models;

use App\Modules\Auth\Domain\Contracts\ProvidesAccountMeta;
use App\Modules\Shared\Authorization\AbilityCatalog;
use App\Modules\Shared\Domain\Contracts\Actor;
use App\Modules\Shared\Domain\Contracts\FullAccount;
use App\Modules\Shared\Domain\Contracts\ManagesNotificationPreferences;
use App\Modules\Shared\Domain\Contracts\ProvidesClockifyCredentials;
use App\Modules\Shared\Domain\Contracts\ProvidesTwoFactorStatus;
use App\Modules\Shared\Domain\Models\AccountNotification;
use App\Modules\Shared\Domain\ValueObjects\InvoiceNumberingProfile;
use App\Modules\Shared\Domain\ValueObjects\SupplierProfile;
use App\Modules\Shared\Enums\Currency;
use App\Modules\Shared\Enums\NotificationCategory;
use App\Modules\Shared\Enums\VatStatus;
use App\Modules\Shared\Exceptions\DomainException;
use App\Modules\Taxation\Domain\Contracts\ProvidesVatFilingSettings;
use App\Modules\Taxation\Domain\Enums\VatFilingFrequency;
use Database\Factories\Modules\Auth\Domain\Models\UserFactory;
use Eloquent;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\DatabaseNotificationCollection;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\HasApiTokens;
use Laravel\Sanctum\NewAccessToken;
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
 * @property bool $notify_invoice_enabled Gates the in-app (database channel) copy only — never mail or the automation trigger itself
 * @property bool $notify_quote_enabled Gates the in-app (database channel) copy only
 * @property bool $notify_tax_enabled Gates the in-app (database channel) copy only
 * @property bool $notify_billing_enabled Gates the in-app (database channel) copy only
 * @property bool $notify_banking_enabled Gates the in-app (database channel) copy only
 * @property bool $notify_system_enabled Gates the in-app (database channel) copy only
 * @property Carbon|null $terms_accepted_at Null for accounts that predate this column — never backfilled, since that would fabricate a consent that never happened
 * @property string|null $terms_version Semver of the terms/privacy doc accepted, e.g. matched against config('gdpr.terms_version')
 * @property Carbon|null $email_verified_at
 * @property Carbon|null $phone_verified_at Set only by ConfirmPhoneVerificationAction; deliberately not fillable
 * @property string|null $remember_token
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read string $full_name
 * @property-read DatabaseNotificationCollection<int, AccountNotification> $notifications
 * @property-read int|null $notifications_count
 * @property-read Collection<int, PersonalAccessToken> $tokens
 * @property-read int|null $tokens_count
 *
 * @method static UserFactory factory($count = null, $state = [])
 * @method static Builder<static> newModelQuery()
 * @method static Builder<static> newQuery()
 * @method static Builder<static> onlyTrashed()
 * @method static Builder<static> query()
 * @method static Builder<static> whereAddress($value)
 * @method static Builder<static> whereAvatarPath($value)
 * @method static Builder<static> whereCity($value)
 * @method static Builder<static> whereColor($value)
 * @method static Builder<static> whereCountry($value)
 * @method static Builder<static> whereCreatedAt($value)
 * @method static Builder<static> whereDefaultCurrency($value)
 * @method static Builder<static> whereDeletedAt($value)
 * @method static Builder<static> whereDic($value)
 * @method static Builder<static> whereEmail($value)
 * @method static Builder<static> whereEmailVerifiedAt($value)
 * @method static Builder<static> whereGoogleId($value)
 * @method static Builder<static> whereIco($value)
 * @method static Builder<static> whereId($value)
 * @method static Builder<static> whereInvoicePrefix($value)
 * @method static Builder<static> whereIsVatPayer($value)
 * @method static Builder<static> whereLocale($value)
 * @method static Builder<static> whereName($value)
 * @method static Builder<static> wherePassword($value)
 * @method static Builder<static> wherePhone($value)
 * @method static Builder<static> wherePostalCode($value)
 * @method static Builder<static> whereRememberToken($value)
 * @method static Builder<static> whereSurname($value)
 * @method static Builder<static> whereTaxFlatRate($value)
 * @method static Builder<static> whereTitle($value)
 * @method static Builder<static> whereUpdatedAt($value)
 * @method static Builder<static> withTrashed(bool $withTrashed = true)
 * @method static Builder<static> withoutTrashed()
 *
 * @mixin Eloquent
 */
class User extends Authenticatable implements Actor, FullAccount, ManagesNotificationPreferences, ProvidesAccountMeta, ProvidesClockifyCredentials, ProvidesTwoFactorStatus, ProvidesVatFilingSettings
{
    use HasApiTokens;

    /** @use HasFactory<UserFactory> */
    use HasFactory;

    use HasUuids;
    use Notifiable;
    use SoftDeletes;

    /**
     * What a purged account's name reads as (PurgeDeletedAccountsAction).
     *
     * Deliberately not translated: it is written to the database once, by a
     * scheduled command running under no locale in particular, and would
     * otherwise be frozen in whatever locale that run happened to have. The
     * surname is emptied rather than repeated, so full_name — which filters
     * blanks — reads as this alone.
     */
    public const ANONYMISED = '[anonymised]';

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
        'notify_invoice_enabled', 'notify_quote_enabled', 'notify_tax_enabled',
        'notify_billing_enabled', 'notify_banking_enabled', 'notify_system_enabled',
        'terms_accepted_at', 'terms_version',
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
        'notify_invoice_enabled' => true,
        'notify_quote_enabled' => true,
        'notify_tax_enabled' => true,
        'notify_billing_enabled' => true,
        'notify_banking_enabled' => true,
        'notify_system_enabled' => true,
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
            'phone_verified_at' => 'datetime',
            'terms_accepted_at' => 'datetime',
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
            'notify_invoice_enabled' => 'boolean',
            'notify_quote_enabled' => 'boolean',
            'notify_tax_enabled' => 'boolean',
            'notify_billing_enabled' => 'boolean',
            'notify_banking_enabled' => 'boolean',
            'notify_system_enabled' => 'boolean',
            'two_factor_secret' => 'encrypted',
            'two_factor_recovery_codes' => 'encrypted:array',
            'two_factor_confirmed_at' => 'datetime',
            'suspended_at' => 'datetime',
        ];
    }

    // ── Suspension ────────────────────────────────────────────────────────────

    /**
     * Suspension applies to the whole account, not one login: a team member
     * of a suspended account is locked out with the owner. Only the SaaS
     * back office ever sets the column — see the migration for why it is
     * still core.
     */
    public function isSuspended(): bool
    {
        return $this->accountOwner()->suspended_at !== null;
    }

    public function suspensionReason(): ?string
    {
        return $this->accountOwner()->suspended_reason;
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

    /**
     * The account as a supplier: the fields printed on a document or filed in
     * a VAT return, without the aggregate that stores them.
     *
     * Handing this to a builder instead of `$this` is what keeps Taxation,
     * Accounting and Integrations from depending on the auth model — see
     * SupplierProfile.
     *
     * The owner's row, never the member's own — same rule as
     * invoiceNumbering() below, and for the same reason: a team member issues
     * documents and files returns as the account, not as themselves. Their own
     * row carries no company_name, IČO or country and its default_currency is
     * whatever they happened to register with, so reading the profile off
     * `$this` answered a different account's question wherever the caller held
     * the authenticated user rather than the owner (statistics currency, the
     * MCP tax summary's residency, Stripe Connect onboarding).
     */
    public function supplierProfile(): SupplierProfile
    {
        $owner = $this->accountOwner();

        return new SupplierProfile(
            name: $owner->supplierName(),
            email: $owner->email,
            phone: $owner->phone,
            ico: $owner->ico,
            dic: $owner->dic,
            vatId: $owner->vat_id,
            vatStatus: $owner->vat_status,
            address: $owner->address,
            city: $owner->city,
            postalCode: $owner->postal_code,
            country: $owner->country,
            defaultCurrency: $owner->default_currency,
            website: $owner->website,
            logoPath: $owner->logo_path,
            invoiceFooterText: $owner->invoice_footer_text,
            firstName: $owner->name,
            lastName: $owner->surname,
        );
    }

    /**
     * The account's invoicing switches — the owner's row throughout, for the
     * same reason invoiceNumbering() resolves it: a team member has no
     * reminder cadence of their own.
     */
    public function invoiceInboxEnabled(): bool
    {
        return (bool) $this->accountOwner()->invoice_inbox_enabled;
    }

    public function overdueDigestEnabled(): bool
    {
        return (bool) $this->accountOwner()->overdue_digest_enabled;
    }

    public function autoRemindEnabled(): bool
    {
        return (bool) $this->accountOwner()->auto_remind_enabled;
    }

    public function autoRemindAfterDays(): int
    {
        return $this->accountOwner()->auto_remind_after_days;
    }

    public function autoRemindMaxCount(): int
    {
        return $this->accountOwner()->auto_remind_max_count;
    }

    public function clockifyApiKey(): ?string
    {
        return $this->accountOwner()->clockify_api_key;
    }

    public function clockifyWorkspaceId(): ?string
    {
        return $this->accountOwner()->clockify_workspace_id;
    }

    public function vatFilingFrequency(): ?VatFilingFrequency
    {
        return $this->accountOwner()->vat_filing_frequency;
    }

    public function taxFilingRemindersEnabled(): bool
    {
        return (bool) $this->accountOwner()->tax_filing_reminder_enabled;
    }

    /**
     * The account-wide AI extraction switch — the owner's row, never the
     * member's own, same rule as invoiceNumbering() below.
     */
    public function aiExtractionEnabled(): bool
    {
        return (bool) $this->accountOwner()->ai_extraction_enabled;
    }

    /**
     * The owner's numbering, never the member's own row: a team member issues
     * documents in the account's series. Resolving that here is the point —
     * it used to be `accountOwner()` repeated at each numbering site.
     */
    public function invoiceNumbering(): InvoiceNumberingProfile
    {
        $owner = $this->accountOwner();

        return new InvoiceNumberingProfile(
            prefix: $owner->invoice_prefix,
            mask: $owner->invoice_number_mask,
            start: $owner->invoice_number_start ?? 1,
            supplierInvoiceMask: $owner->supplier_invoice_number_mask,
            supplierInvoiceStart: $owner->supplier_invoice_number_start ?? 1,
            quoteMask: $owner->quote_number_mask,
            quoteStart: $owner->quote_number_start ?? 1,
        );
    }

    public function hasTaxResidency(): bool
    {
        return $this->country !== null;
    }

    /**
     * Whether the front end should surface the terms/privacy re-acceptance
     * prompt — true both for an account that never accepted and for one
     * whose accepted version fell behind config('gdpr.terms_version').
     */
    public function termsAcceptanceRequired(): bool
    {
        return $this->terms_version !== config('gdpr.terms_version');
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

    /**
     * Whether this recipient wants an in-app (database channel) copy of a
     * notification in the given category — checked by InAppNotification::
     * via(), never by mail or by the automation trigger that decided to
     * send in the first place.
     */
    public function wantsNotificationCategory(NotificationCategory $category): bool
    {
        return match ($category) {
            NotificationCategory::Invoice => $this->notify_invoice_enabled,
            NotificationCategory::Quote => $this->notify_quote_enabled,
            NotificationCategory::Tax => $this->notify_tax_enabled,
            NotificationCategory::Billing => $this->notify_billing_enabled,
            NotificationCategory::Banking => $this->notify_banking_enabled,
            NotificationCategory::System => $this->notify_system_enabled,
        };
    }

    /**
     * Personal, not account-wide — see ManagesNotificationPreferences: these
     * six columns are the one set on the row that means the *person*, so this
     * writes `$this` rather than accountOwner().
     */
    public function updateNotificationPreferences(array $wanted): void
    {
        $changes = [];

        foreach ($wanted as $category => $enabled) {
            $changes[self::notificationColumn(NotificationCategory::from($category))] = $enabled;
        }

        if ($changes !== []) {
            $this->forceFill($changes)->save();
        }
    }

    private static function notificationColumn(NotificationCategory $category): string
    {
        return match ($category) {
            NotificationCategory::Invoice => 'notify_invoice_enabled',
            NotificationCategory::Quote => 'notify_quote_enabled',
            NotificationCategory::Tax => 'notify_tax_enabled',
            NotificationCategory::Billing => 'notify_billing_enabled',
            NotificationCategory::Banking => 'notify_banking_enabled',
            NotificationCategory::System => 'notify_system_enabled',
        };
    }

    public function hasPassword(): bool
    {
        return $this->password !== null;
    }

    /**
     * Every path that signs a user in (password login, Google OAuth, 2FA
     * challenge, invitation accept) goes through this rather than
     * createToken() directly, so `type` is never missing on a login token —
     * SessionController relies on it to tell a login token apart from an
     * integration one created via PersonalAccessTokenController.
     */
    public function createSessionToken(string $name, ?string $ipAddress = null, ?string $userAgent = null): NewAccessToken
    {
        $token = $this->createToken($name);

        $token->accessToken->forceFill([
            'type' => 'session',
            'ip_address' => $ipAddress,
            'user_agent' => $userAgent,
        ])->save();

        return $token;
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

    /**
     * Laravel's own locale contract, honoured automatically by Mailable and
     * Notification. Documents and mail sent *on the account's behalf* go out
     * in the account's language, which is why other modules ask for this
     * rather than reading the column — the column is storage, this is the
     * contract, and only the latter survives the module boundary.
     */
    public function preferredLocale(): ?string
    {
        return $this->locale;
    }

    public function accountOwner(): self
    {
        return $this;
    }

    public function actorId(): string
    {
        return $this->id;
    }

    /**
     * The core edition is single-account: there are no team members, so
     * every user is the owner of their own account. The SaaS user model
     * overrides this against owner_id.
     */
    public function isOwner(): bool
    {
        return true;
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
     * Plan-limit hook for callers that report the remaining quota instead of
     * just enforcing it. -1 is unlimited, which is what the core (OSS)
     * edition answers for every key — it has no plans to be limited by.
     */
    public function planLimit(string $limitKey): int
    {
        return -1;
    }

    /**
     * Plan gate for card payments on invoices. False in the core (OSS)
     * edition, which has no Stripe Connect at all — the same answer its
     * AlwaysUnavailableOnlinePayment binding gives per invoice. The SaaS
     * user model overrides this against the plan's online_payments column.
     */
    public function allowsOnlinePayments(): bool
    {
        return false;
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

    /**
     * Platform kill-switch hook, for the front end to hide a nav entry
     * instead of waiting on a 403. The core (OSS) edition has no platform
     * settings to disable a feature from. The SaaS user model overrides
     * this against saas.disabled_features.
     *
     * @return list<string>
     */
    public function disabledFeatures(): array
    {
        return [];
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
}
