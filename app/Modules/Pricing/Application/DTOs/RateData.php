<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Application\DTOs;

use App\Modules\Pricing\Domain\Enums\RateLevel;
use App\Modules\Shared\Enums\Currency;
use Illuminate\Http\Request;
use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\Nullable;
use Spatie\LaravelData\Data;

class RateData extends Data
{
    public function __construct(
        public readonly RateLevel $level,

        public readonly float $rate,

        #[Nullable]
        public readonly ?string $client_id = null,

        #[Nullable]
        public readonly ?string $order_id = null,

        #[Nullable]
        public readonly ?Currency $currency = null,

        #[Nullable]
        public readonly ?string $valid_from = null,

        #[Nullable, Max(255)]
        public readonly ?string $note = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        return [
            'level' => ['required', 'in:'.implode(',', RateLevel::knownValues())],
            'rate' => ['required', 'numeric', 'min:0'],
            'client_id' => ['nullable', 'uuid', 'exists:clients,id'],
            'order_id' => ['nullable', 'uuid', 'exists:orders,id'],
            'currency' => ['nullable', 'in:'.implode(',', Currency::knownValues())],
            'valid_from' => ['nullable', 'date'],
            'note' => ['nullable', 'string', 'max:255'],
        ];
    }

    public static function fromRequest(Request $request): self
    {
        return new self(
            level: RateLevel::from($request->string('level')->toString()),
            rate: $request->float('rate'),
            client_id: $request->filled('client_id') ? $request->string('client_id')->toString() : null,
            order_id: $request->filled('order_id') ? $request->string('order_id')->toString() : null,
            currency: $request->filled('currency') ? Currency::from($request->string('currency')->toString()) : null,
            valid_from: $request->filled('valid_from') ? $request->string('valid_from')->toString() : null,
            note: $request->filled('note') ? $request->string('note')->toString() : null,
        );
    }
}
