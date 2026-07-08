<?php

declare(strict_types=1);

namespace App\Modules\Orders\Application\DTOs;

use App\Modules\Shared\Enums\BillingType;
use App\Modules\Shared\Enums\Currency;
use Illuminate\Http\Request;
use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\Nullable;
use Spatie\LaravelData\Data;

class OrderData extends Data
{
    public function __construct(
        #[Max(255)]
        public readonly string $name,

        public readonly BillingType $billing_type,

        #[Nullable]
        public readonly ?string $client_id,

        #[Nullable, Max(7)]
        public readonly ?string $color,

        #[Nullable]
        public readonly ?string $readme,

        #[Nullable]
        public readonly ?float $rate,

        #[Nullable]
        public readonly ?Currency $currency,

        #[Nullable]
        public readonly ?float $estimated_hours,

        #[Nullable]
        public readonly ?float $estimated_price,

        #[Nullable]
        public readonly ?string $deadline,

        public readonly string $status = 'active',
    ) {}

    /**
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        return [
            'client_id' => ['nullable', 'uuid', 'exists:clients,id'],
            'color' => ['nullable', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'rate' => ['nullable', 'numeric', 'min:0'],
            'estimated_hours' => ['nullable', 'numeric', 'min:0'],
            'estimated_price' => ['nullable', 'numeric', 'min:0'],
            'deadline' => ['nullable', 'date'],
            'status' => ['in:active,paused,completed,archived'],
        ];
    }

    public static function fromRequest(Request $request): self
    {
        return new self(
            name: $request->string('name')->toString(),
            billing_type: BillingType::from($request->string('billing_type')->toString()),
            client_id: $request->filled('client_id') ? $request->string('client_id')->toString() : null,
            color: $request->filled('color') ? $request->string('color')->toString() : null,
            readme: $request->filled('readme') ? $request->string('readme')->toString() : null,
            rate: $request->filled('rate') ? $request->float('rate') : null,
            currency: $request->filled('currency') ? Currency::from($request->string('currency')->toString()) : null,
            estimated_hours: $request->filled('estimated_hours') ? $request->float('estimated_hours') : null,
            estimated_price: $request->filled('estimated_price') ? $request->float('estimated_price') : null,
            deadline: $request->filled('deadline') ? $request->string('deadline')->toString() : null,
            status: $request->string('status', 'active')->toString(),
        );
    }
}
