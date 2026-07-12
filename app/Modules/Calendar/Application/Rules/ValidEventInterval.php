<?php

declare(strict_types=1);

namespace App\Modules\Calendar\Application\Rules;

use Carbon\CarbonImmutable;
use Closure;
use DateTimeInterface;
use Illuminate\Contracts\Validation\DataAwareRule;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Validates the `ends_at` field against the sibling `starts_at` field:
 * end must be after start, span at least one slot, and end no later than
 * midnight of the day `starts_at` falls on.
 *
 * Silently passes when `starts_at` is missing/unparseable — that field's
 * own rules are responsible for reporting the error.
 */
final class ValidEventInterval implements DataAwareRule, ValidationRule
{
    /** @var array<string, mixed> */
    private array $data = [];

    /**
     * @param  array<string, mixed>  $data
     */
    public function setData(array $data): static
    {
        $this->data = $data;

        return $this;
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $startsAtRaw = $this->data['starts_at'] ?? null;

        if (! is_string($startsAtRaw) && ! $startsAtRaw instanceof DateTimeInterface) {
            return;
        }

        if (! is_string($value) && ! $value instanceof DateTimeInterface) {
            return;
        }

        try {
            $startsAt = CarbonImmutable::parse($startsAtRaw);
            $endsAt = CarbonImmutable::parse($value);
        } catch (\Exception) {
            return;
        }

        if (! $endsAt->greaterThan($startsAt)) {
            $fail(__('calendar.validation.ends_before_starts'));

            return;
        }

        $slotMinutes = (int) config('calendar.slot_minutes');

        if ($startsAt->diffInMinutes($endsAt) < $slotMinutes) {
            $fail(__('calendar.validation.min_duration', ['minutes' => $slotMinutes]));

            return;
        }

        if ($endsAt->greaterThan($startsAt->startOfDay()->addDay())) {
            $fail(__('calendar.validation.must_end_same_day'));
        }
    }
}
