<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Application\DTOs;

use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\Nullable;
use Spatie\LaravelData\Attributes\Validation\Regex;
use Spatie\LaravelData\Data;

class VatRateData extends Data
{
    public function __construct(
        #[Max(10)]
        public readonly string $code,

        #[Regex('/^[A-Z]{2}$/')]
        public readonly string $country,

        public readonly float $rate,

        #[Nullable, Max(255)]
        public readonly ?string $label = null,

        public readonly bool $is_default = false,

        #[Nullable]
        public readonly ?string $valid_from = null,

        #[Nullable]
        public readonly ?string $valid_to = null,
    ) {}

    /**
     * @param  string|null  $userId  Scopes the uniqueness check to this tenant when given.
     * @return array<string, mixed>
     */
    public static function rules(?string $userId = null, ?string $ignoreId = null): array
    {
        return [
            'code' => [
                'required', 'string', 'max:10',
                ...($userId !== null ? [
                    Rule::unique('vat_rates', 'code')->where('user_id', $userId)->ignore($ignoreId),
                ] : []),
            ],
            'country' => ['required', 'string', 'regex:/^[A-Z]{2}$/'],
            'rate' => ['required', 'numeric', 'min:0', 'max:100'],
            'label' => ['nullable', 'string', 'max:255'],
            'is_default' => ['sometimes', 'boolean'],
            'valid_from' => ['nullable', 'date'],
            'valid_to' => ['nullable', 'date', 'after_or_equal:valid_from'],
        ];
    }

    public static function fromRequest(Request $request): self
    {
        return new self(
            code: $request->string('code')->toString(),
            country: strtoupper($request->string('country')->toString()),
            rate: $request->float('rate'),
            label: $request->filled('label') ? $request->string('label')->toString() : null,
            is_default: $request->boolean('is_default'),
            valid_from: $request->filled('valid_from') ? $request->string('valid_from')->toString() : null,
            valid_to: $request->filled('valid_to') ? $request->string('valid_to')->toString() : null,
        );
    }
}
