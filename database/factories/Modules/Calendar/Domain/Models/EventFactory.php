<?php

declare(strict_types=1);

namespace Database\Factories\Modules\Calendar\Domain\Models;

use App\Modules\Auth\Domain\Models\User;
use App\Modules\Calendar\Domain\Enums\EventSource;
use App\Modules\Calendar\Domain\Models\Event;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * @extends Factory<Event>
 */
class EventFactory extends Factory
{
    protected $model = Event::class;

    public function definition(): array
    {
        $day = Carbon::instance(fake()->dateTimeBetween('-1 month', '+1 month'))->startOfDay();
        $startsAt = $day->clone()->addMinutes(fake()->numberBetween(0, 92) * 15);
        $slots = fake()->numberBetween(1, 8);
        $endsAt = $startsAt->clone()->addMinutes($slots * 15);

        return [
            'user_id' => User::factory(),
            'title' => fake()->sentence(3),
            'description' => fake()->optional()->paragraph(),
            'location' => fake()->optional()->address(),
            'color' => fake()->optional()->hexColor(),
            'is_all_day' => false,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'source' => EventSource::Manual,
            'external_uid' => null,
        ];
    }

    public function allDay(): static
    {
        return $this->state(function (array $attributes): array {
            $day = Carbon::parse($attributes['starts_at'])->startOfDay();

            return [
                'is_all_day' => true,
                'starts_at' => $day,
                'ends_at' => $day->clone()->addDay(),
            ];
        });
    }

    public function endedBefore(CarbonImmutable $cutoff): static
    {
        return $this->state(function (array $attributes) use ($cutoff): array {
            $slots = fake()->numberBetween(1, 8);
            $endsAt = Carbon::instance($cutoff)->subMinutes(fake()->numberBetween(1, 24) * 60);
            $startsAt = $endsAt->clone()->subMinutes($slots * 15);

            return [
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
            ];
        });
    }

    public function imported(EventSource $source, string $uid): static
    {
        return $this->state(fn (array $attributes): array => [
            'source' => $source,
            'external_uid' => $uid,
        ]);
    }
}
