<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Application\DTOs;

use App\Modules\Invoicing\Domain\Enums\InvoiceType;
use App\Modules\Shared\Enums\Currency;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Spatie\LaravelData\Attributes\Validation\Nullable;
use Spatie\LaravelData\Data;

class InvoiceData extends Data
{
    public function __construct(
        public readonly string $client_id,
        public readonly string $issued_at,
        public readonly string $due_at,
        public readonly Currency $currency,

        public readonly InvoiceType $type = InvoiceType::Invoice,

        #[Nullable]
        public readonly ?string $taxable_supply_at = null,

        #[Nullable]
        public readonly ?string $variable_symbol = null,

        #[Nullable]
        public readonly ?string $bank_account_id = null,

        #[Nullable]
        public readonly ?float $discount_percent = null,

        #[Nullable]
        public readonly ?string $note = null,

        #[Nullable]
        public readonly ?string $note_above = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        return [
            'client_id' => ['required', 'uuid'],
            'issued_at' => ['required', 'date'],
            'due_at' => ['required', 'date', 'after_or_equal:issued_at'],
            'currency' => ['required', Rule::enum(Currency::class)],
            'type' => ['sometimes', Rule::enum(InvoiceType::class)],
            'taxable_supply_at' => ['nullable', 'date'],
            'variable_symbol' => ['nullable', 'string', 'regex:/^\d{1,10}$/'],
            'bank_account_id' => ['nullable', 'uuid', 'exists:bank_accounts,id'],
            'discount_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'note' => ['nullable', 'string', 'max:2000'],
            'note_above' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public static function fromRequest(Request $request): self
    {
        return new self(
            client_id: $request->string('client_id')->toString(),
            issued_at: $request->string('issued_at')->toString(),
            due_at: $request->string('due_at')->toString(),
            currency: Currency::from($request->string('currency')->toString()),
            type: $request->filled('type')
                ? InvoiceType::from($request->string('type')->toString())
                : InvoiceType::Invoice,
            taxable_supply_at: $request->filled('taxable_supply_at') ? $request->string('taxable_supply_at')->toString() : null,
            variable_symbol: $request->filled('variable_symbol') ? $request->string('variable_symbol')->toString() : null,
            bank_account_id: $request->filled('bank_account_id') ? $request->string('bank_account_id')->toString() : null,
            discount_percent: $request->filled('discount_percent') ? (float) $request->input('discount_percent') : null,
            note: $request->filled('note') ? $request->string('note')->toString() : null,
            note_above: $request->filled('note_above') ? $request->string('note_above')->toString() : null,
        );
    }
}
