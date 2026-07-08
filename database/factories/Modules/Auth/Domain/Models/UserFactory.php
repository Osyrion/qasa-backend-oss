<?php

declare(strict_types=1);

namespace Database\Factories\Modules\Auth\Domain\Models;

use App\Modules\Auth\Domain\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    protected $model = User::class;

    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'title' => fake()->optional()->title(),
            'name' => fake()->firstName(),
            'surname' => fake()->lastName(),
            'email' => fake()->unique()->safeEmail(),
            'phone' => fake()->optional()->phoneNumber(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'avatar_path' => null,
            'color' => fake()->hexColor(),
            'ico' => fake()->optional()->numerify('########'),
            'dic' => fake()->optional()->numerify('##########'),
            'is_vat_payer' => fake()->boolean(35),
            'tax_flat_rate' => fake()->randomElement([0, 40, 60]),
            'default_currency' => fake()->randomElement(['CZK', 'EUR', 'USD']),
            'invoice_prefix' => 'FA',
            'locale' => fake()->randomElement(['cs', 'sk', 'en']),
            'country' => fake()->randomElement(['CZ', 'SK']),
            'address' => fake()->optional()->streetAddress(),
            'city' => fake()->optional()->city(),
            'postal_code' => fake()->optional()->postcode(),
            'remember_token' => Str::random(10),
        ];
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }
}
