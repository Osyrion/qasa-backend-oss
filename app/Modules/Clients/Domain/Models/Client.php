<?php

declare(strict_types=1);

namespace App\Modules\Clients\Domain\Models;

use App\Modules\Auth\Domain\Models\User;
use App\Modules\Invoicing\Domain\Models\Invoice;
use App\Modules\Orders\Domain\Models\Order;
use App\Modules\Shared\Enums\Currency;
use App\Modules\Shared\Traits\HasUserScope;
use Database\Factories\Modules\Clients\Domain\Models\ClientFactory;
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
 * @property string $client_type
 * @property string|null $title
 * @property string|null $name
 * @property string|null $surname
 * @property string|null $company_name
 * @property string|null $avatar_path
 * @property string|null $color Hex color
 * @property string|null $ico
 * @property string|null $dic
 * @property string|null $vat_id IČ DPH / VAT ID
 * @property bool $is_vat_payer
 * @property bool $reverse_charge_allowed Domestic reverse charge opt-in
 * @property Carbon|null $vat_verified_at Last successful VIES check
 * @property bool $is_customer
 * @property bool $is_vendor
 * @property string|null $email
 * @property string|null $phone
 * @property string|null $address
 * @property string|null $city
 * @property string|null $postal_code
 * @property string $country ISO 3166-1 alpha-2
 * @property Currency $currency
 * @property string $locale Invoice language
 * @property string|null $note
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read Collection<int, ContactPerson> $contactPersons
 * @property-read int|null $contact_persons_count
 * @property-read string $display_name
 * @property-read Collection<int, Invoice> $invoices
 * @property-read int|null $invoices_count
 * @property-read Collection<int, Order> $orders
 * @property-read int|null $orders_count
 * @property-read Collection<int, ContactPerson> $primaryContactPerson
 * @property-read int|null $primary_contact_person_count
 * @property-read User|null $user
 *
 * @method static ClientFactory factory($count = null, $state = [])
 * @method static Builder<static>|Client forUser($userId = null)
 * @method static Builder<static>|Client newModelQuery()
 * @method static Builder<static>|Client newQuery()
 * @method static Builder<static>|Client onlyTrashed()
 * @method static Builder<static>|Client query()
 * @method static Builder<static>|Client whereAddress($value)
 * @method static Builder<static>|Client whereAvatarPath($value)
 * @method static Builder<static>|Client whereCity($value)
 * @method static Builder<static>|Client whereClientType($value)
 * @method static Builder<static>|Client whereColor($value)
 * @method static Builder<static>|Client whereCompanyName($value)
 * @method static Builder<static>|Client whereCountry($value)
 * @method static Builder<static>|Client whereCreatedAt($value)
 * @method static Builder<static>|Client whereCurrency($value)
 * @method static Builder<static>|Client whereDeletedAt($value)
 * @method static Builder<static>|Client whereDic($value)
 * @method static Builder<static>|Client whereEmail($value)
 * @method static Builder<static>|Client whereIco($value)
 * @method static Builder<static>|Client whereId($value)
 * @method static Builder<static>|Client whereIsCustomer($value)
 * @method static Builder<static>|Client whereIsVatPayer($value)
 * @method static Builder<static>|Client whereIsVendor($value)
 * @method static Builder<static>|Client whereLocale($value)
 * @method static Builder<static>|Client whereName($value)
 * @method static Builder<static>|Client whereNote($value)
 * @method static Builder<static>|Client wherePhone($value)
 * @method static Builder<static>|Client wherePostalCode($value)
 * @method static Builder<static>|Client whereSurname($value)
 * @method static Builder<static>|Client whereTitle($value)
 * @method static Builder<static>|Client whereUpdatedAt($value)
 * @method static Builder<static>|Client whereUserId($value)
 * @method static Builder<static>|Client withTrashed(bool $withTrashed = true)
 * @method static Builder<static>|Client withoutTrashed()
 * @method static Builder<static>|Client whereReverseChargeAllowed($value)
 * @method static Builder<static>|Client whereVatId($value)
 * @method static Builder<static>|Client whereVatVerifiedAt($value)
 *
 * @mixin Eloquent
 */
class Client extends Model
{
    /** @use HasFactory<ClientFactory> */
    use HasFactory;

    use HasUserScope;
    use HasUuids;
    use SoftDeletes;

    protected $fillable = [
        'user_id',
        'client_type',
        'title',
        'name',
        'surname',
        'company_name',
        'avatar_path',
        'color',
        'ico',
        'dic',
        'vat_id',
        'is_vat_payer',
        'reverse_charge_allowed',
        'vat_verified_at',
        'is_customer',
        'is_vendor',
        'email',
        'phone',
        'address',
        'city',
        'postal_code',
        'country',
        'currency',
        'locale',
        'note',
    ];

    protected function casts(): array
    {
        return [
            'is_vat_payer' => 'boolean',
            'reverse_charge_allowed' => 'boolean',
            'vat_verified_at' => 'datetime',
            'is_customer' => 'boolean',
            'is_vendor' => 'boolean',
            'currency' => Currency::class,
        ];
    }

    // ── Computed ──────────────────────────────────────────────────────────────

    public function getDisplayNameAttribute(): string
    {
        return match ($this->client_type) {
            'company' => $this->company_name ?? '',
            'self_employed' => trim(implode(' ', array_filter([
                $this->company_name,
                $this->name ? '('.$this->name.' '.$this->surname.')' : null,
            ]))),
            default => trim(implode(' ', array_filter([
                $this->title,
                $this->name,
                $this->surname,
            ]))),
        };
    }

    public function isCompany(): bool
    {
        return $this->client_type === 'company';
    }

    public function isIndividual(): bool
    {
        return $this->client_type === 'individual';
    }

    public function isSelfEmployed(): bool
    {
        return $this->client_type === 'self_employed';
    }

    public function canHaveContactPersons(): bool
    {
        return $this->isCompany();
    }

    public function isCustomer(): bool
    {
        return $this->is_customer;
    }

    public function isVendor(): bool
    {
        return $this->is_vendor;
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
     * @return HasMany<ContactPerson, $this>
     */
    public function contactPersons(): HasMany
    {
        return $this->hasMany(ContactPerson::class);
    }

    /**
     * @return HasMany<ContactPerson, $this>
     */
    public function primaryContactPerson(): HasMany
    {
        return $this->hasMany(ContactPerson::class)->where('is_primary', true);
    }

    /**
     * @return HasMany<Order, $this>
     */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    /**
     * @return HasMany<Invoice, $this>
     */
    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }
}
