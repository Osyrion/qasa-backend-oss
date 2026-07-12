<?php

declare(strict_types=1);

namespace App\Modules\TimeTracking\Application\DTOs;

use App\Modules\Invoicing\Domain\Rules\VatRateInCatalog;
use Illuminate\Http\Request;
use Spatie\LaravelData\Attributes\Validation\Nullable;
use Spatie\LaravelData\Data;

class TimeEntryData extends Data
{
    public function __construct(
        public readonly string $order_id,
        public readonly string $started_at,
        public readonly float $vat_rate = 0.0,
        public readonly bool $is_billable = true,

        #[Nullable]
        public readonly ?string $order_item_id = null,

        #[Nullable]
        public readonly ?string $description = null,

        #[Nullable]
        public readonly ?string $ended_at = null,

        #[Nullable]
        public readonly ?int $duration_seconds = null,

        #[Nullable]
        public readonly ?float $rate_override = null,
    ) {}

    /**
     * @param  string|null  $userId  Validates vat_rate against the tenant's catalog when given.
     * @return array<string, mixed>
     */
    public static function rules(?string $userId = null, ?string $country = null): array
    {
        return [
            'order_id' => ['required', 'uuid', 'exists:orders,id'],
            'order_item_id' => ['nullable', 'uuid'],
            'description' => ['nullable', 'string', 'max:1000'],
            'started_at' => ['required', 'date'],
            'ended_at' => ['nullable', 'date', 'after:started_at'],
            'duration_seconds' => ['nullable', 'integer', 'min:1'],
            'rate_override' => ['nullable', 'numeric', 'min:0'],
            'vat_rate' => [
                'sometimes', 'numeric', 'min:0', 'max:100',
                ...($userId !== null && $country !== null ? [new VatRateInCatalog($userId, $country)] : []),
            ],
            'is_billable' => ['sometimes', 'boolean'],
        ];
    }

    public static function fromRequest(Request $request): self
    {
        return new self(
            order_id: $request->string('order_id')->toString(),
            started_at: $request->string('started_at')->toString(),
            vat_rate: $request->float('vat_rate', 0.0),
            is_billable: $request->boolean('is_billable', true),
            order_item_id: $request->filled('order_item_id') ? $request->string('order_item_id')->toString() : null,
            description: $request->filled('description') ? $request->string('description')->toString() : null,
            ended_at: $request->filled('ended_at') ? $request->string('ended_at')->toString() : null,
            duration_seconds: $request->filled('duration_seconds') ? $request->integer('duration_seconds') : null,
            rate_override: $request->filled('rate_override') ? $request->float('rate_override') : null,
        );
    }
}
