<?php

declare(strict_types=1);

namespace Database\Factories\Modules\Taxation\Domain\Models;

use App\Modules\Taxation\Domain\Enums\TaxFilingStatus;
use App\Modules\Taxation\Domain\Enums\TaxFilingType;
use App\Modules\Taxation\Domain\Models\TaxFiling;
use Database\Factories\BoundAccount;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TaxFiling>
 */
class TaxFilingFactory extends Factory
{
    protected $model = TaxFiling::class;

    public function definition(): array
    {
        $content = '<test-fixture/>';

        return [
            'user_id' => fn (): string => BoundAccount::id(),
            'type' => TaxFilingType::ControlStatement->value,
            'country' => 'SK',
            'period_year' => (int) now()->format('Y'),
            'period_quarter' => null,
            'period_month' => now()->month,
            'content' => $content,
            'sha256' => hash('sha256', $content),
            'status' => TaxFilingStatus::Generated->value,
            'filed_at' => null,
            'notes' => null,
            'supersedes_id' => null,
        ];
    }

    public function filed(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => TaxFilingStatus::Filed->value,
            'filed_at' => now(),
        ]);
    }

    public function superseded(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => TaxFilingStatus::Superseded->value,
        ]);
    }
}
