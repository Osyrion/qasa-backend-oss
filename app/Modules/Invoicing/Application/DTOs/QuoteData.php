<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Application\DTOs;

use App\Modules\Shared\Enums\Currency;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Spatie\LaravelData\Attributes\Validation\Nullable;
use Spatie\LaravelData\Data;

class QuoteData extends Data
{
    public function __construct(
        public readonly string $client_id,
        public readonly string $issued_at,
        public readonly Currency $currency,

        #[Nullable]
        public readonly ?string $valid_until = null,

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
            'currency' => ['required', Rule::enum(Currency::class)],
            'valid_until' => ['nullable', 'date', 'after_or_equal:issued_at'],
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
            currency: Currency::from($request->string('currency')->toString()),
            valid_until: $request->filled('valid_until') ? $request->string('valid_until')->toString() : null,
            discount_percent: $request->filled('discount_percent') ? (float) $request->input('discount_percent') : null,
            note: $request->filled('note') ? $request->string('note')->toString() : null,
            note_above: $request->filled('note_above') ? $request->string('note_above')->toString() : null,
        );
    }
}
