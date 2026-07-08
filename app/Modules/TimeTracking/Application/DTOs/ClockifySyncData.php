<?php

declare(strict_types=1);

namespace App\Modules\TimeTracking\Application\DTOs;

use Illuminate\Http\Request;
use Spatie\LaravelData\Data;

class ClockifySyncData extends Data
{
    public function __construct(
        public readonly string $order_id,
        public readonly string $date_from,
        public readonly string $date_to,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        return [
            'order_id' => ['required', 'uuid', 'exists:orders,id'],
            'date_from' => ['required', 'date'],
            'date_to' => ['required', 'date', 'after_or_equal:date_from'],
        ];
    }

    public static function fromRequest(Request $request): self
    {
        return new self(
            order_id: $request->string('order_id')->toString(),
            date_from: $request->string('date_from')->toString(),
            date_to: $request->string('date_to')->toString(),
        );
    }
}
