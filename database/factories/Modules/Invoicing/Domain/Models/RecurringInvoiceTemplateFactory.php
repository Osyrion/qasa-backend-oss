<?php

declare(strict_types=1);

namespace Database\Factories\Modules\Invoicing\Domain\Models;

use App\Modules\Auth\Domain\Models\User;
use App\Modules\Invoicing\Domain\Enums\InvoiceType;
use App\Modules\Invoicing\Domain\Enums\RecurringPeriod;
use App\Modules\Invoicing\Domain\Enums\RecurringTemplateStatus;
use App\Modules\Invoicing\Domain\Enums\TaxDateMode;
use App\Modules\Invoicing\Domain\Models\RecurringInvoiceTemplate;
use App\Modules\Shared\Enums\Currency;
use Database\Factories\Modules\Clients\Domain\Models\ClientFactory;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RecurringInvoiceTemplate>
 */
class RecurringInvoiceTemplateFactory extends Factory
{
    protected $model = RecurringInvoiceTemplate::class;

    public function definition(): array
    {
        $firstIssueDate = fake()->dateTimeBetween('now', '+1 month')->format('Y-m-d');

        return [
            'user_id' => User::factory(),
            'client_id' => ClientFactory::new(),
            'name' => fake()->words(3, true),
            'status' => RecurringTemplateStatus::Active->value,
            'period' => RecurringPeriod::Monthly->value,
            'day_of_month' => fake()->numberBetween(1, 28),
            'last_day_of_month' => false,
            'first_issue_date' => $firstIssueDate,
            'end_date' => null,
            'next_run_date' => $firstIssueDate,
            'last_generated_at' => null,
            'type' => InvoiceType::Invoice->value,
            'currency' => Currency::CZK->value,
            'due_days' => 14,
            'discount_percent' => null,
            'tax_date_mode' => TaxDateMode::IssueDate->value,
            'auto_send' => false,
            'note_above' => null,
            'note_below' => null,
        ];
    }

    public function dueToday(): static
    {
        return $this->state(fn (array $attributes): array => [
            'first_issue_date' => now()->toDateString(),
            'next_run_date' => now()->toDateString(),
        ]);
    }

    public function paused(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => RecurringTemplateStatus::Paused->value,
        ]);
    }

    public function expired(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => RecurringTemplateStatus::Expired->value,
        ]);
    }

    public function lastDayOfMonth(): static
    {
        return $this->state(fn (array $attributes): array => [
            'last_day_of_month' => true,
        ]);
    }

    public function proforma(): static
    {
        return $this->state(fn (array $attributes): array => [
            'type' => InvoiceType::Proforma->value,
        ]);
    }
}
