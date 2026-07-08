<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Application\DTOs;

use App\Modules\Shared\Enums\Currency;
use Illuminate\Http\Request;
use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\Nullable;
use Spatie\LaravelData\Data;

class PriceListData extends Data
{
    public function __construct(
        #[Max(150)]
        public readonly string $name,

        #[Nullable]
        public readonly ?string $description = null,

        #[Nullable]
        public readonly ?Currency $currency = null,

        #[Nullable, Max(2)]
        public readonly ?string $country = null,

        public readonly bool $is_default = false,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string'],
            'currency' => ['nullable', 'in:'.implode(',', Currency::knownValues())],
            'country' => ['nullable', 'string', 'size:2'],
            'is_default' => ['boolean'],
        ];
    }

    public static function fromRequest(Request $request): self
    {
        return new self(
            name: $request->string('name')->toString(),
            description: $request->filled('description') ? $request->string('description')->toString() : null,
            currency: $request->filled('currency') ? Currency::from($request->string('currency')->toString()) : null,
            country: $request->filled('country') ? strtoupper($request->string('country')->toString()) : null,
            is_default: $request->boolean('is_default'),
        );
    }
}
