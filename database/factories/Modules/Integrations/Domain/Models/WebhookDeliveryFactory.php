<?php

declare(strict_types=1);

namespace Database\Factories\Modules\Integrations\Domain\Models;

use App\Modules\Integrations\Domain\Models\WebhookDelivery;
use App\Modules\Integrations\Domain\Models\WebhookEndpoint;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WebhookDelivery>
 */
class WebhookDeliveryFactory extends Factory
{
    protected $model = WebhookDelivery::class;

    public function definition(): array
    {
        return [
            'webhook_endpoint_id' => WebhookEndpoint::factory(),
            'event' => 'invoice.created',
            'payload' => ['id' => fake()->uuid()],
            'attempt' => 1,
            'response_status' => 200,
            'response_excerpt' => 'OK',
            'delivered_at' => now(),
        ];
    }

    public function failed(): static
    {
        return $this->state(fn (array $attributes): array => [
            'response_status' => null,
            'response_excerpt' => 'Connection timed out',
            'delivered_at' => null,
            'failed_at' => now(),
        ]);
    }
}
