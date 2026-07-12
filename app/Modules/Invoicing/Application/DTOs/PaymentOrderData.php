<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Application\DTOs;

use Illuminate\Http\Request;
use Spatie\LaravelData\Attributes\Validation\Nullable;
use Spatie\LaravelData\Data;

class PaymentOrderData extends Data
{
    /**
     * @param  list<string>  $supplier_invoice_ids
     */
    public function __construct(
        public readonly string $bank_account_id,
        public readonly string $due_date,
        public readonly array $supplier_invoice_ids,

        #[Nullable]
        public readonly ?string $constant_symbol = null,

        #[Nullable]
        public readonly ?string $note = null,

        public readonly bool $mark_paid = false,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        return [
            'bank_account_id' => ['required', 'uuid'],
            'due_date' => ['required', 'date'],
            'constant_symbol' => ['nullable', 'digits:4'],
            'note' => ['nullable', 'string', 'max:2000'],
            'supplier_invoice_ids' => ['required', 'array', 'min:1'],
            'supplier_invoice_ids.*' => ['uuid', 'distinct'],
            'mark_paid' => ['sometimes', 'boolean'],
        ];
    }

    public static function fromRequest(Request $request): self
    {
        /** @var list<string> $ids */
        $ids = array_values($request->array('supplier_invoice_ids'));

        return new self(
            bank_account_id: $request->string('bank_account_id')->toString(),
            due_date: $request->string('due_date')->toString(),
            supplier_invoice_ids: $ids,
            constant_symbol: $request->filled('constant_symbol') ? $request->string('constant_symbol')->toString() : null,
            note: $request->filled('note') ? $request->string('note')->toString() : null,
            mark_paid: $request->boolean('mark_paid'),
        );
    }
}
