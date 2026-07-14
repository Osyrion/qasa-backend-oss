<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Application\DTOs;

use Illuminate\Http\Request;
use Spatie\LaravelData\Attributes\Validation\Nullable;
use Spatie\LaravelData\Data;

class PaymentData extends Data
{
    public function __construct(
        public readonly float $amount,
        public readonly string $paid_at,

        #[Nullable]
        public readonly ?string $method = null,

        #[Nullable]
        public readonly ?string $note = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        return [
            'amount' => ['required', 'numeric', 'gt:0', 'max:99999999'],
            'paid_at' => ['required', 'date'],
            'method' => ['nullable', 'string', 'in:bank_transfer,cash,card,other'],
            'note' => ['nullable', 'string', 'max:255'],
        ];
    }

    public static function fromRequest(Request $request): self
    {
        return new self(
            amount: (float) $request->input('amount'),
            paid_at: $request->string('paid_at')->toString(),
            method: $request->filled('method') ? $request->string('method')->toString() : null,
            note: $request->filled('note') ? $request->string('note')->toString() : null,
        );
    }
}
