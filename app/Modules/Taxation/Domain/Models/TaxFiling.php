<?php

declare(strict_types=1);

namespace App\Modules\Taxation\Domain\Models;

use App\Modules\Shared\Exceptions\DomainException;
use App\Modules\Shared\Traits\HasUserScope;
use App\Modules\Taxation\Domain\Enums\TaxFilingStatus;
use App\Modules\Taxation\Domain\Enums\TaxFilingType;
use Database\Factories\Modules\Taxation\Domain\Models\TaxFilingFactory;
use Eloquent;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * An immutable snapshot of a generated filing — a new snapshot is always a
 * new row (the old one flips to Superseded via $supersedes pointing back at
 * it), never an edit of the content that was actually generated. The model
 * itself is the last line of defense (`booted()` below), same pattern as
 * User's residency lock — even a bug elsewhere in the call chain can't
 * silently rewrite what a compliance record says was filed.
 *
 * @property string $id
 * @property string $user_id
 * @property TaxFilingType $type
 * @property string $country SK|CZ — residency at generation time
 * @property int $period_year
 * @property int|null $period_quarter
 * @property int|null $period_month
 * @property string $content Raw generated document (XML for control_statement, JSON for eu_sales_list)
 * @property string $sha256
 * @property TaxFilingStatus $status
 * @property Carbon|null $filed_at
 * @property string|null $notes
 * @property string|null $supersedes_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read TaxFiling|null $supersedes
 *
 * @method static TaxFilingFactory factory($count = null, $state = [])
 * @method static Builder<static> forUser($userId = null)
 * @method static Builder<static> newModelQuery()
 * @method static Builder<static> newQuery()
 * @method static Builder<static> query()
 *
 * @mixin Eloquent
 */
class TaxFiling extends Model
{
    /** @use HasFactory<TaxFilingFactory> */
    use HasFactory;

    use HasUserScope;
    use HasUuids;

    /**
     * Only these may ever change after creation — everything that describes
     * *what was generated* is fixed forever.
     *
     * @var list<string>
     */
    private const MUTABLE_AFTER_CREATE = ['status', 'filed_at', 'notes', 'updated_at'];

    protected $fillable = [
        'user_id',
        'type',
        'country',
        'period_year',
        'period_quarter',
        'period_month',
        'content',
        'sha256',
        'status',
        'filed_at',
        'notes',
        'supersedes_id',
    ];

    protected function casts(): array
    {
        return [
            'type' => TaxFilingType::class,
            'status' => TaxFilingStatus::class,
            'period_year' => 'integer',
            'period_quarter' => 'integer',
            'period_month' => 'integer',
            'filed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (self $filing): void {
            $illegallyDirty = array_diff(array_keys($filing->getDirty()), self::MUTABLE_AFTER_CREATE);

            if ($illegallyDirty !== []) {
                throw DomainException::because(__('taxation.tax_filing_immutable'));
            }
        });
    }

    /**
     * @return BelongsTo<TaxFiling, $this>
     */
    public function supersedes(): BelongsTo
    {
        return $this->belongsTo(self::class, 'supersedes_id');
    }
}
