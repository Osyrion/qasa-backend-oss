<?php

declare(strict_types=1);

namespace App\Modules\Calendar\Application\DTOs;

use App\Modules\Calendar\Application\Rules\AlignedToQuarterHour;
use App\Modules\Calendar\Application\Rules\ValidEventInterval;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Support\Validation\ValidationContext;

class EventData extends Data
{
    public function __construct(
        public readonly string $title,
        public readonly string $starts_at,
        public readonly bool $is_all_day = false,
        public readonly ?string $ends_at = null,
        public readonly ?string $description = null,
        public readonly ?string $location = null,
        public readonly ?string $color = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public static function rules(ValidationContext $context): array
    {
        $isAllDay = (bool) ($context->fullPayload['is_all_day'] ?? false);

        return [
            'title' => ['required', 'string', 'max:255'],
            'starts_at' => $isAllDay
                ? ['required', 'date']
                : ['required', 'date', new AlignedToQuarterHour],
            'is_all_day' => ['boolean'],
            'ends_at' => $isAllDay
                ? ['nullable', 'date']
                : ['required', 'date', new AlignedToQuarterHour, new ValidEventInterval],
            'description' => ['nullable', 'string'],
            'location' => ['nullable', 'string', 'max:255'],
            'color' => ['nullable', 'regex:/^#[0-9A-Fa-f]{6}$/'],
        ];
    }
}
