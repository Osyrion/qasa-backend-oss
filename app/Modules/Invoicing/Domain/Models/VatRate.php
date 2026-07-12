<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Domain\Models;

use App\Modules\Auth\Domain\Models\User;
use App\Modules\Shared\Traits\HasUserScope;
use Database\Factories\Modules\Invoicing\Domain\Models\VatRateFactory;
use Eloquent;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Per-tenant VAT rate catalog. Invoice/template items validate their numeric
 * rate against this catalog at write time (see
 * App\Modules\Invoicing\Domain\Rules\VatRateInCatalog) rather than holding a
 * foreign key, so the rate on an issued item stays a frozen snapshot even
 * after a catalog entry expires or is deleted.
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
 * @property-read User|null $user
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
    public function isValidOn(Carbon $date): bool
    {
        if ($this->valid_from !== null && $date->lt($this->valid_from)) {
            return false;
        }

        if ($this->valid_to !== null && $date->gt($this->valid_to)) {
            return false;
        }

        return true;
    }

    // ── Relations ─────────────────────────────────────────────────────────────

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
