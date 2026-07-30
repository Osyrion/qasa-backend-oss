<?php

declare(strict_types=1);

namespace Database\Factories\Modules\Invoicing\Domain\Models;

use App\Modules\Invoicing\Domain\Enums\AiProvider;
use App\Modules\Invoicing\Domain\Models\AiCredential;
use Database\Factories\BoundAccount;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AiCredential>
 */
class AiCredentialFactory extends Factory
{
    protected $model = AiCredential::class;

    public function definition(): array
    {
        return [
            'user_id' => fn (): string => BoundAccount::id(),
            'provider' => AiProvider::Anthropic->value,
            'api_key' => 'sk-ant-'.fake()->uuid(),
            'verified_at' => null,
            'last_error' => null,
        ];
    }

    public function verified(): static
    {
        return $this->state(fn (array $attributes): array => [
            'verified_at' => now(),
        ]);
    }
}
