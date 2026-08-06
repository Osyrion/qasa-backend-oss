<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Domain\Models;

use App\Modules\Shared\Enums\PaymentMethod;
use App\Modules\Shared\Enums\Provenance;
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
 * @property PaymentMethod|null $method
 * @property Provenance $provenance How this payment came to exist — manual|email_in|api|import|auto_matched|ai_suggested
 * @property string|null $note
 * @property string|null $bank_reference External transaction id from an imported bank statement
 * @property string|null $stripe_payment_intent_id Set only for payments recorded by the Stripe Connect webhook
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
        'provenance',
        'note',
        'bank_reference',
        'stripe_payment_intent_id',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'paid_at' => 'date',
            'method' => PaymentMethod::class,
            'provenance' => Provenance::class,
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
