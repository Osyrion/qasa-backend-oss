<?php

declare(strict_types=1);

namespace Database\Factories\Modules\Clients\Domain\Models;

use App\Modules\Auth\Domain\Models\User;
use App\Modules\Clients\Domain\Enums\ClientType;
use App\Modules\Clients\Domain\Models\Client;
use App\Modules\Shared\Enums\Currency;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Client>
 */
class ClientFactory extends Factory
{
    protected $model = Client::class;

    public function definition(): array
    {
        $type = fake()->randomElement(ClientType::cases());

        return [
            'user_id' => User::factory(),
            'client_type' => $type->value,
            'title' => $type === ClientType::Individual ? fake()->optional()->title() : null,
            'name' => $type !== ClientType::Company ? fake()->firstName() : null,
            'surname' => $type !== ClientType::Company ? fake()->lastName() : null,
            'company_name' => $type !== ClientType::Individual ? fake()->company() : null,
            'avatar_path' => null,
            'color' => fake()->hexColor(),
            'ico' => fake()->optional()->numerify('########'),
            'dic' => fake()->optional()->numerify('##########'),
            'is_vat_payer' => fake()->boolean(35),
            'is_customer' => true,
            'is_vendor' => false,
            'email' => fake()->safeEmail(),
            'phone' => fake()->optional()->phoneNumber(),
            'address' => fake()->streetAddress(),
            'city' => fake()->city(),
            'postal_code' => fake()->postcode(),
            'country' => fake()->randomElement(['CZ', 'SK']),
            'currency' => fake()->randomElement(Currency::cases())->value,
            'locale' => fake()->randomElement(['cs', 'sk', 'en']),
            'note' => fake()->optional()->sentence(),
        ];
    }

    public function company(): static
    {
        return $this->state(fn (array $attributes): array => [
            'client_type' => ClientType::Company->value,
            'title' => null,
            'name' => null,
            'surname' => null,
            'company_name' => fake()->company(),
        ]);
    }

    public function individual(): static
    {
        return $this->state(fn (array $attributes): array => [
            'client_type' => ClientType::Individual->value,
            'name' => fake()->firstName(),
            'surname' => fake()->lastName(),
            'company_name' => null,
        ]);
    }

    public function vendor(): static
    {
        return $this->state(fn (array $attributes): array => [
            'is_customer' => false,
            'is_vendor' => true,
        ]);
    }

    public function both(): static
    {
        return $this->state(fn (array $attributes): array => [
            'is_customer' => true,
            'is_vendor' => true,
        ]);
    }
}
