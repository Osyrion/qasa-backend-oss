<?php

declare(strict_types=1);

namespace Database\Factories\Modules\Orders\Domain\Models;

use App\Modules\Orders\Domain\Models\OrderAttachment;
use Database\Factories\BoundAccount;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OrderAttachment>
 */
class OrderAttachmentFactory extends Factory
{
    protected $model = OrderAttachment::class;

    public function definition(): array
    {
        $filename = fake()->word().'.pdf';

        return [
            'order_id' => fn (array $attributes) => OrderFactory::new()->create(['user_id' => $attributes['user_id']]),
            'user_id' => fn (): string => BoundAccount::id(),
            'disk' => 'local',
            'path' => 'orders/'.fake()->uuid().'/'.$filename,
            'external_id' => null,
            'external_url' => null,
            'filename' => $filename,
            'mime_type' => 'application/pdf',
            'size_bytes' => fake()->numberBetween(10_000, 5_000_000),
            'label' => fake()->optional()->words(2, true),
            'sort_order' => fake()->numberBetween(0, 100),
        ];
    }

    public function external(string $disk = 'sharepoint'): static
    {
        return $this->state(fn (array $attributes): array => [
            'disk' => $disk,
            'path' => null,
            'external_id' => fake()->uuid(),
            'external_url' => fake()->url(),
        ]);
    }
}
