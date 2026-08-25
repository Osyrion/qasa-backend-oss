<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Domain\Models;

use App\Modules\Shared\Traits\HasUserScope;
use Carbon\CarbonImmutable;
use Database\Factories\Modules\Invoicing\Domain\Models\VatRateFactory;
use Eloquent;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Per-tenant VAT rate catalog. Not enforced against InvoiceItem/OrderItem's
 * free-form numeric vat_rate — it exists purely for CRUD/lookup, so deleting
 * or editing a catalog entry never touches already-issued documents.
 *
 * @property string $id
 * @property string $user_id
 * @property string $code e.g. SK-23
 * @property string $country ISO 3166-1 alpha-2
 * @property numeric $rate
 * @property string|null $label
 * @property bool $is_default Default rate for its user+country
 * @property Carbon|null $valid_from
 * @property Carbon|null $valid_to
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 *
 * @method static VatRateFactory factory($count = null, $state = [])
 * @method static Builder<static>|VatRate forUser($userId = null)
 * @method static Builder<static>|VatRate newModelQuery()
 * @method static Builder<static>|VatRate newQuery()
 * @method static Builder<static>|VatRate query()
 *
 * @mixin Eloquent
 */
class VatRate extends Model
{
    /** @use HasFactory<VatRateFactory> */
    use HasFactory;

    use HasUserScope;
    use HasUuids;

    protected $fillable = [
        'user_id',
        'code',
        'country',
        'rate',
        'label',
        'is_default',
        'valid_from',
        'valid_to',
    ];

    protected function casts(): array
    {
        return [
            'rate' => 'decimal:2',
            'is_default' => 'boolean',
            'valid_from' => 'date',
            'valid_to' => 'date',
        ];
    }

    /**
     * Whether this catalog entry is in force on the given date (inclusive
     * bounds; null bounds mean unbounded on that side).
     */
    public function isValidOn(CarbonImmutable $date): bool
    {
        if ($this->valid_from !== null && $date->lt($this->valid_from)) {
            return false;
        }

        if ($this->valid_to !== null && $date->gt($this->valid_to)) {
            return false;
        }

        return true;
    }
}
