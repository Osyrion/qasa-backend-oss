<?php

declare(strict_types=1);

namespace App\Modules\Calendar\Application\DTOs;

use Spatie\LaravelData\Data;

class EventRangeData extends Data
{
    public function __construct(
        public readonly ?string $from = null,
        public readonly ?string $to = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        return [
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ];
    }
}
