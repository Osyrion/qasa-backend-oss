<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Application\DTOs;

use App\Modules\Invoicing\Domain\Enums\CashDocumentType;
use App\Modules\Shared\Enums\Currency;
use Illuminate\Validation\Rule;
use Spatie\LaravelData\Attributes\Validation\Nullable;
use Spatie\LaravelData\Data;

class CashDocumentData extends Data
{
    public function __construct(
        public readonly CashDocumentType $type,
        public readonly string $issued_at,
        public readonly float $amount,
        public readonly Currency $currency,
        public readonly string $description,

        #[Nullable]
        public readonly ?float $vat_rate = null,

        #[Nullable]
        public readonly ?float $vat_amount = null,

        #[Nullable]
        public readonly ?string $counterparty = null,

        #[Nullable]
        public readonly ?string $note = null,

        #[Nullable]
        public readonly ?string $invoice_payment_id = null,

        #[Nullable]
        public readonly ?string $expense_id = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        return [
            'type' => ['required', Rule::enum(CashDocumentType::class)],
            'issued_at' => ['required', 'date'],
            // Direction is the type's job; a negative amount would be a
            // second, contradictory way to say the same thing.
            'amount' => ['required', 'numeric', 'gt:0'],
            'currency' => ['required', Rule::enum(Currency::class)],
            'description' => ['required', 'string', 'max:255'],
            'vat_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'vat_amount' => ['nullable', 'numeric', 'min:0'],
            'counterparty' => ['nullable', 'string', 'max:255'],
            'note' => ['nullable', 'string', 'max:2000'],
            // At most one link: a document either papers over an existing
            // payment, or over an existing expense, or over neither.
            'invoice_payment_id' => ['nullable', 'uuid', 'prohibits:expense_id'],
            'expense_id' => ['nullable', 'uuid'],
        ];
    }
}
