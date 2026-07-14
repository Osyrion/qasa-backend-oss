<?php

declare(strict_types=1);

namespace Database\Factories\Modules\Shared\Domain\Models;

use App\Modules\Auth\Domain\Models\User;
use App\Modules\Shared\Domain\Models\ActivityLog;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ActivityLog>
 */
class ActivityLogFactory extends Factory
{
    protected $model = ActivityLog::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'actor_id' => null,
            'subject_type' => 'client',
            'subject_id' => fake()->uuid(),
            'event' => 'client.created',
            'changes' => [],
        ];
    }
}
