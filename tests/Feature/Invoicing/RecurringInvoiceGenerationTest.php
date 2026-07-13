<?php

declare(strict_types=1);

use App\Modules\Clients\Domain\Models\Client;
use App\Modules\Invoicing\Domain\Enums\InvoiceStatus;
use App\Modules\Invoicing\Domain\Models\BankAccount;
use App\Modules\Invoicing\Domain\Models\Invoice;
use App\Modules\Invoicing\Domain\Models\RecurringInvoiceTemplate;

/**
 * @param  array<string, mixed>  $attributes
 * @param  array<string, mixed>  $itemAttributes
 */
function recurringTemplate(array $attributes = [], array $itemAttributes = []): RecurringInvoiceTemplate
{
    $user = createUser(['invoice_prefix' => 'FA']);
    $client = Client::factory()->create(['user_id' => $user->id]);

    $template = RecurringInvoiceTemplate::factory()->dueToday()->create([
        'user_id' => $user->id,
        'client_id' => $client->id,
        'currency' => 'CZK',
        'due_days' => 14,
        ...$attributes,
    ]);

    $template->items()->create([
        'description' => 'Hosting',
        'quantity' => 1,
        'unit' => 'ks',
        'unit_price' => 1000,
        'vat_rate' => 21,
        'sort_order' => 0,
        ...$itemAttributes,
    ]);

    return $template->refresh();
}

it('generates a draft invoice from a due template', function (): void {
    $template = recurringTemplate([
        'discount_percent' => 10,
        'note_above' => 'Vyúčtování za období {BOM} – {EOM}',
        'note_below' => 'Děkujeme za využívání služeb.',
    ]);

    $bankAccount = BankAccount::factory()->create([
        'user_id' => $template->user_id,
        'currency' => 'CZK',
        'is_default' => true,
    ]);

    $this->artisan('qasa:invoices:generate-recurring')->assertSuccessful();

    $invoice = Invoice::withoutGlobalScope('user')->firstOrFail();
    $today = today();

    expect($invoice->status)->toBe(InvoiceStatus::Draft)
        ->and($invoice->invoice_number)->toBe('FA-'.now()->format('Y').'-001')
        ->and($invoice->recurring_template_id)->toBe($template->id)
        ->and($invoice->issued_at->toDateString())->toBe($today->toDateString())
        ->and($invoice->due_at->toDateString())->toBe($today->copy()->addDays(14)->toDateString())
        ->and($invoice->taxable_supply_at?->toDateString())->toBe($today->toDateString())
        ->and($invoice->bank_account_id)->toBe($bankAccount->id)
        ->and((float) $invoice->discount_percent)->toBe(10.0)
        ->and($invoice->note_above)->toBe(
            'Vyúčtování za období '.$today->copy()->startOfMonth()->format('j.n.Y')
            .' – '.$today->copy()->endOfMonth()->format('j.n.Y'),
        )
        ->and($invoice->note)->toBe('Děkujeme za využívání služeb.')
        ->and($invoice->items)->toHaveCount(1)
        ->and((float) $invoice->subtotal)->toBe(1000.0)
        ->and((float) $invoice->discount_amount)->toBe(100.0)
        ->and((float) $invoice->vat_amount)->toBe(189.0)
        ->and((float) $invoice->total)->toBe(1089.0);

    $template->refresh();

    expect($template->last_generated_at?->toDateString())->toBe($today->toDateString())
        ->and($template->next_run_date->toDateString())
        ->toBe($template->period->nextDate(
            $today->toImmutable(),
            $template->day_of_month,
            $template->last_day_of_month,
        )->toDateString());
});

it('resolves DUZP to the previous month end and placeholders against it', function (): void {
    recurringTemplate(
        ['tax_date_mode' => 'previous_month_end'],
        ['description' => 'Hosting {MONTH}'],
    );

    $this->artisan('qasa:invoices:generate-recurring')->assertSuccessful();

    $invoice = Invoice::withoutGlobalScope('user')->with('items')->firstOrFail();
    $previousMonthEnd = today()->startOfMonth()->subDay();

    expect($invoice->taxable_supply_at?->toDateString())->toBe($previousMonthEnd->toDateString())
        ->and($invoice->items->firstOrFail()->description)->toBe('Hosting '.$previousMonthEnd->format('m/Y'));
});

it('generates proforma without DUZP, placeholders against the issue date', function (): void {
    recurringTemplate(
        ['type' => 'proforma'],
        ['description' => 'Hosting {MONTH}'],
    );

    $this->artisan('qasa:invoices:generate-recurring')->assertSuccessful();

    $invoice = Invoice::withoutGlobalScope('user')->with('items')->firstOrFail();

    expect($invoice->type->value)->toBe('proforma')
        ->and($invoice->taxable_supply_at)->toBeNull()
        ->and($invoice->invoice_number)->toStartWith('PF-')
        ->and($invoice->items->firstOrFail()->description)->toBe('Hosting '.today()->format('m/Y'));
});

it('is idempotent — a second run the same day generates nothing', function (): void {
    recurringTemplate();

    $this->artisan('qasa:invoices:generate-recurring')->assertSuccessful();
    $this->artisan('qasa:invoices:generate-recurring')->assertSuccessful();

    expect(Invoice::withoutGlobalScope('user')->count())->toBe(1);
});

it('catches up one invoice per missed period', function (): void {
    $template = recurringTemplate([
        'first_issue_date' => today()->subMonths(2)->toDateString(),
        'next_run_date' => today()->subMonths(2)->toDateString(),
        'day_of_month' => today()->subMonths(2)->day,
    ]);

    $this->artisan('qasa:invoices:generate-recurring')->assertSuccessful();

    $issuedDates = Invoice::withoutGlobalScope('user')
        ->orderBy('issued_at')
        ->pluck('issued_at')
        ->map(fn ($date) => $date->toDateString());

    expect($issuedDates)->toHaveCount(3)
        ->and($issuedDates[0])->toBe($template->first_issue_date->toDateString());
});

it('expires the template once the next occurrence passes end_date', function (): void {
    recurringTemplate([
        'end_date' => today()->addDays(3)->toDateString(),
    ]);

    $this->artisan('qasa:invoices:generate-recurring')->assertSuccessful();

    $template = RecurringInvoiceTemplate::withoutGlobalScope('user')->firstOrFail();

    expect(Invoice::withoutGlobalScope('user')->count())->toBe(1)
        ->and($template->isExpired())->toBeTrue();

    $this->artisan('qasa:invoices:generate-recurring')->assertSuccessful();

    expect(Invoice::withoutGlobalScope('user')->count())->toBe(1);
});

it('skips paused and expired templates', function (): void {
    $user = createUser(['invoice_prefix' => 'FA']);
    $client = Client::factory()->create(['user_id' => $user->id]);

    RecurringInvoiceTemplate::factory()->dueToday()->paused()->create([
        'user_id' => $user->id, 'client_id' => $client->id,
    ]);
    RecurringInvoiceTemplate::factory()->dueToday()->expired()->create([
        'user_id' => $user->id, 'client_id' => $client->id,
    ]);

    $this->artisan('qasa:invoices:generate-recurring')->assertSuccessful();

    expect(Invoice::withoutGlobalScope('user')->count())->toBe(0);
});

it('isolates a failing template and still processes the rest', function (): void {
    $broken = recurringTemplate();
    $broken->client()->firstOrFail()->delete();

    $healthy = recurringTemplate();

    $this->artisan('qasa:invoices:generate-recurring')->assertFailed();

    $broken->refresh();

    expect($broken->isPaused())->toBeTrue()
        ->and(Invoice::withoutGlobalScope('user')->count())->toBe(1)
        ->and(Invoice::withoutGlobalScope('user')->firstOrFail()->user_id)->toBe($healthy->user_id);
});

it('issues catch-up invoices dated as originally scheduled, not today', function (): void {
    $pastDate = today()->subMonth();

    recurringTemplate([
        'first_issue_date' => $pastDate->toDateString(),
        'next_run_date' => $pastDate->toDateString(),
        'day_of_month' => min($pastDate->day, 28),
    ]);

    $this->artisan('qasa:invoices:generate-recurring')->assertSuccessful();

    $first = Invoice::withoutGlobalScope('user')->orderBy('issued_at')->firstOrFail();

    expect($first->issued_at->toDateString())->toBe($pastDate->toDateString());
});
