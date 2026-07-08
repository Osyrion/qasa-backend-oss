<?php

declare(strict_types=1);

namespace Database\Factories\Modules\TimeTracking\Domain\Models;

use App\Modules\Auth\Domain\Models\User;
use App\Modules\TimeTracking\Domain\Models\TimeEntry;
use Database\Factories\Modules\Orders\Domain\Models\OrderFactory;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TimeEntry>
 */
class TimeEntryFactory extends Factory
{
    protected $model = TimeEntry::class;

    public function definition(): array
    {
        $startedAt = fake()->dateTimeBetween('-1 month', 'now');
        $durationSeconds = fake()->numberBetween(900, 28_800);
        $endedAt = (clone $startedAt)->modify('+'.$durationSeconds.' seconds');

        return [
            'user_id' => User::factory(),
            'order_id' => OrderFactory::new(),
            'order_item_id' => null,
            'description' => fake()->optional()->sentence(4),
            'started_at' => $startedAt,
            'ended_at' => $endedAt,
            'duration_seconds' => $durationSeconds,
            'rate_override' => fake()->optional()->randomFloat(2, 20, 150),
            'vat_rate' => fake()->randomElement([0, 10, 20, 21, 23]),
            'is_billable' => fake()->boolean(80),
            'is_invoiced' => false,
            'source' => 'manual',
            'external_id' => null,
        ];
    }

    public function running(): static
    {
        return $this->state(fn (array $attributes): array => [
            'ended_at' => null,
            'duration_seconds' => null,
        ]);
    }

    public function invoiced(): static
    {
        return $this->state(fn (array $attributes): array => [
            'is_invoiced' => true,
        ]);
    }
}
