<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Domain\Models;

use Database\Factories\Modules\Invoicing\Domain\Models\PaymentOrderItemFactory;
use Eloquent;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Frozen payment row of a batch — vendor, account, symbols and amount as
 * they were when the batch was created.
 *
 * @property string $id
 * @property string $payment_order_id
 * @property string|null $supplier_invoice_id
 * @property string $vendor_name
 * @property string $supplier_invoice_number
 * @property string|null $account_number Domestic format [prefix-]number
 * @property string|null $bank_code
 * @property string|null $iban
 * @property string|null $bic
 * @property string|null $variable_symbol
 * @property numeric $amount
 * @property int $sort_order
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read PaymentOrder|null $paymentOrder
 * @property-read SupplierInvoice|null $supplierInvoice
 *
 * @method static PaymentOrderItemFactory factory($count = null, $state = [])
 * @method static Builder<static>|PaymentOrderItem newModelQuery()
 * @method static Builder<static>|PaymentOrderItem newQuery()
 * @method static Builder<static>|PaymentOrderItem query()
 *
 * @mixin Eloquent
 */
class PaymentOrderItem extends Model
{
    /** @use HasFactory<PaymentOrderItemFactory> */
    use HasFactory;

    use HasUuids;

    protected $fillable = [
        'payment_order_id',
        'supplier_invoice_id',
        'vendor_name',
        'supplier_invoice_number',
        'account_number',
        'bank_code',
        'iban',
        'bic',
        'variable_symbol',
        'amount',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
        ];
    }

    public function hasDomesticAccount(): bool
    {
        return $this->account_number !== null && $this->account_number !== ''
            && $this->bank_code !== null && $this->bank_code !== '';
    }

    // ── Relations ─────────────────────────────────────────────────────────────

    /**
     * @return BelongsTo<PaymentOrder, $this>
     */
    public function paymentOrder(): BelongsTo
    {
        return $this->belongsTo(PaymentOrder::class);
    }

    /**
     * @return BelongsTo<SupplierInvoice, $this>
     */
    public function supplierInvoice(): BelongsTo
    {
        return $this->belongsTo(SupplierInvoice::class);
    }
}
