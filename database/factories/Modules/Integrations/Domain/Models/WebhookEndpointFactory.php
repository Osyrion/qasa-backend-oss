<?php

declare(strict_types=1);

namespace Database\Factories\Modules\Integrations\Domain\Models;

use App\Modules\Auth\Domain\Models\User;
use App\Modules\Integrations\Domain\Models\WebhookEndpoint;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<WebhookEndpoint>
 */
class WebhookEndpointFactory extends Factory
{
    protected $model = WebhookEndpoint::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'url' => 'https://example.com/webhooks/'.fake()->uuid(),
            'secret' => Str::random(40),
            'events' => ['invoice.created', 'invoice.paid'],
            'is_active' => true,
            'consecutive_failures' => 0,
        ];
    }

    public function disabled(): static
    {
        return $this->state(fn (array $attributes): array => [
            'is_active' => false,
            'disabled_at' => now(),
            'consecutive_failures' => 10,
        ]);
    }
}
