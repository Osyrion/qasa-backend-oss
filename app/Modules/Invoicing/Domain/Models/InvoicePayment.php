<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Domain\Models;

use Database\Factories\Modules\Invoicing\Domain\Models\InvoicePaymentFactory;
use Eloquent;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $invoice_id
 * @property numeric $amount In the invoice currency
 * @property Carbon $paid_at
 * @property string|null $method bank_transfer | cash | card | other
 * @property string|null $note
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Invoice|null $invoice
 *
 * @method static InvoicePaymentFactory factory($count = null, $state = [])
 * @method static Builder<static>|InvoicePayment newModelQuery()
 * @method static Builder<static>|InvoicePayment newQuery()
 * @method static Builder<static>|InvoicePayment query()
 *
 * @mixin Eloquent
 */
class InvoicePayment extends Model
{
    /** @use HasFactory<InvoicePaymentFactory> */
    use HasFactory;

    use HasUuids;

    protected $fillable = [
        'invoice_id',
        'amount',
        'paid_at',
        'method',
        'note',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'paid_at' => 'date',
        ];
    }

    /**
     * @return BelongsTo<Invoice, $this>
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }
}
