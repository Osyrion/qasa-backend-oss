<?php

declare(strict_types=1);

namespace Database\Factories\Modules\Clients\Domain\Models;

use App\Modules\Clients\Domain\Models\ContactPerson;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ContactPerson>
 */
class ContactPersonFactory extends Factory
{
    protected $model = ContactPerson::class;

    public function definition(): array
    {
        return [
            'client_id' => ClientFactory::new()->company(),
            'title' => fake()->optional()->title(),
            'name' => fake()->firstName(),
            'surname' => fake()->lastName(),
            'email' => fake()->safeEmail(),
            'phone' => fake()->optional()->phoneNumber(),
            'role' => fake()->optional()->jobTitle(),
            'is_primary' => fake()->boolean(25),
        ];
    }

    public function primary(): static
    {
        return $this->state(fn (array $attributes): array => [
            'is_primary' => true,
        ]);
    }
}
