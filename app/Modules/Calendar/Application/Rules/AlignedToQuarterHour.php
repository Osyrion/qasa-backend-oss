<?php

declare(strict_types=1);

namespace App\Modules\Calendar\Application\Rules;

use Carbon\CarbonImmutable;
use Closure;
use DateTimeInterface;
use Illuminate\Contracts\Validation\ValidationRule;

final class AlignedToQuarterHour implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) && ! $value instanceof DateTimeInterface) {
            return;
        }

        try {
            $dateTime = CarbonImmutable::parse($value);
        } catch (\Exception) {
            return;
        }

        $slotMinutes = (int) config('calendar.slot_minutes');

        if ($dateTime->second !== 0 || $dateTime->minute % $slotMinutes !== 0) {
            $fail(__('calendar.validation.not_aligned', ['minutes' => $slotMinutes]));
        }
    }
}
