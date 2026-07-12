<?php

declare(strict_types=1);

namespace Database\Factories\Modules\Auth\Domain\Models;

use App\Modules\Auth\Domain\Models\User;
use App\Modules\Shared\Enums\VatStatus;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;

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
            'vat_status' => fake()->randomElement(VatStatus::cases())->value,
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

    public function payer(): static
    {
        return $this->state(fn (array $attributes) => [
            'vat_status' => VatStatus::Payer->value,
            'is_vat_payer' => true,
        ]);
    }

    public function identified(): static
    {
        return $this->state(fn (array $attributes) => [
            'vat_status' => VatStatus::Identified->value,
            'is_vat_payer' => false,
        ]);
    }

    public function nonPayer(): static
    {
        return $this->state(fn (array $attributes) => [
            'vat_status' => VatStatus::NonPayer->value,
            'is_vat_payer' => false,
        ]);
    }

    /**
     * Confirmed 2FA with a known secret (so tests can compute valid TOTP
     * codes) and optional known plaintext recovery codes (hashed here).
     *
     * @param  list<string>  $recoveryCodes
     */
    public function withTwoFactor(?string $secret = null, array $recoveryCodes = ['recovery-code-1', 'recovery-code-2']): static
    {
        return $this->state(fn (array $attributes) => [
            'two_factor_secret' => $secret ?? (new Google2FA)->generateSecretKey(),
            'two_factor_confirmed_at' => now(),
            'two_factor_recovery_codes' => array_map(static fn (string $code): string => Hash::make($code), $recoveryCodes),
        ]);
    }
}
