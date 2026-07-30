<?php

declare(strict_types=1);

namespace Database\Factories\Modules\Shared\Domain\Models;

use App\Modules\Shared\Domain\Models\ActivityLog;
use Database\Factories\BoundAccount;
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
            'user_id' => fn (): string => BoundAccount::id(),
            'actor_id' => null,
            'subject_type' => 'client',
            'subject_id' => fake()->uuid(),
            'event' => 'client.created',
            'changes' => [],
        ];
    }
}
