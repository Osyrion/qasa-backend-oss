<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Domain\Models;

use App\Modules\TimeTracking\Domain\Models\TimeEntry;
use Database\Factories\Modules\Invoicing\Domain\Models\InvoiceWorkReportLineFactory;
use Eloquent;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One row of the "Výkaz víceprací" printed as the invoice's second page.
 * Prefilled from time entries, editable while the invoice is a draft.
 *
 * @property string $id
 * @property string $invoice_id
 * @property string|null $time_entry_id
 * @property Carbon $work_date
 * @property string $description
 * @property numeric $hours
 * @property int $sort_order
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Invoice|null $invoice
 * @property-read TimeEntry|null $timeEntry
 *
 * @method static InvoiceWorkReportLineFactory factory($count = null, $state = [])
 * @method static Builder<static>|InvoiceWorkReportLine newModelQuery()
 * @method static Builder<static>|InvoiceWorkReportLine newQuery()
 * @method static Builder<static>|InvoiceWorkReportLine query()
 *
 * @mixin Eloquent
 */
class InvoiceWorkReportLine extends Model
{
    /** @use HasFactory<InvoiceWorkReportLineFactory> */
    use HasFactory;

    use HasUuids;

    protected $fillable = [
        'invoice_id',
        'time_entry_id',
        'work_date',
        'description',
        'hours',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'work_date' => 'date',
            'hours' => 'decimal:2',
            'sort_order' => 'integer',
        ];
    }

    // ── Relations ─────────────────────────────────────────────────────────────

    /**
     * @return BelongsTo<Invoice, $this>
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /**
     * @return BelongsTo<TimeEntry, $this>
     */
    public function timeEntry(): BelongsTo
    {
        return $this->belongsTo(TimeEntry::class);
    }
}
