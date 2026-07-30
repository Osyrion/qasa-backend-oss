<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Domain\Models;

use Database\Factories\Modules\Invoicing\Domain\Models\RecurringInvoiceTemplateItemFactory;
use Eloquent;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $template_id
 * @property string $description May contain period placeholders ({BOM}, {EOM}, {MONTH}, {YEAR})
 * @property numeric $quantity
 * @property string $unit
 * @property numeric $unit_price Excl. VAT
 * @property numeric $vat_rate
 * @property int $sort_order
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read RecurringInvoiceTemplate|null $template
 *
 * @method static RecurringInvoiceTemplateItemFactory factory($count = null, $state = [])
 * @method static Builder<static>|RecurringInvoiceTemplateItem newModelQuery()
 * @method static Builder<static>|RecurringInvoiceTemplateItem newQuery()
 * @method static Builder<static>|RecurringInvoiceTemplateItem query()
 *
 * @mixin Eloquent
 */
class RecurringInvoiceTemplateItem extends Model
{
    /** @use HasFactory<RecurringInvoiceTemplateItemFactory> */
    use HasFactory;

    use HasUuids;

    protected $fillable = [
        'template_id',
        'description',
        'quantity',
        'unit',
        'unit_price',
        'vat_rate',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:3',
            'unit_price' => 'decimal:2',
            'vat_rate' => 'decimal:2',
            'sort_order' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<RecurringInvoiceTemplate, $this>
     */
    public function template(): BelongsTo
    {
        return $this->belongsTo(RecurringInvoiceTemplate::class, 'template_id');
    }
}
