<?php

declare(strict_types=1);

use App\Modules\Auth\Domain\Models\User;
use App\Modules\Clients\Domain\Models\Client;
use App\Modules\Invoicing\Application\Services\VatRateSeederService;
use App\Modules\Invoicing\Domain\Models\Invoice;
use App\Modules\Invoicing\Domain\Models\RecurringInvoiceTemplate;

function rcTemplateOwner(string $vatStatus = 'payer'): User
{
    $user = createUser(['country' => 'SK', 'vat_status' => $vatStatus, 'invoice_prefix' => 'FA']);
    app(VatRateSeederService::class)->seedFor($user);

    return $user;
}

it('rejects a non-payer creating a template with a non-zero VAT rate item', function (): void {
    $user = rcTemplateOwner('non_payer');
    $client = Client::factory()->create(['user_id' => $user->id]);

    $this->actingAs($user)->postJson('/api/v1/recurring-invoice-templates', [
        'name' => 'Hosting',
        'client_id' => $client->id,
        'period' => 'monthly',
        'day_of_month' => 1,
        'first_issue_date' => today()->addDay()->toDateString(),
        'currency' => 'EUR',
        'due_days' => 14,
        'items' => [
            ['description' => 'Hosting', 'quantity' => 1, 'unit' => 'ks', 'unit_price' => 100, 'vat_rate' => 23],
        ],
    ])->assertStatus(422);
});

it('re-resolves EU reverse charge at generation time once the client gets a VAT ID', function (): void {
    $user = rcTemplateOwner();
    $client = Client::factory()->create(['user_id' => $user->id, 'country' => 'DE', 'vat_id' => null]);

    $template = RecurringInvoiceTemplate::factory()->dueToday()->create([
        'user_id' => $user->id,
        'client_id' => $client->id,
        'currency' => 'EUR',
        'due_days' => 14,
    ]);
    $template->items()->create([
        'description' => 'Služby', 'quantity' => 1, 'unit' => 'ks',
        'unit_price' => 1000, 'vat_rate' => 23, 'sort_order' => 0,
    ]);

    $this->artisan('qasa:invoices:generate-recurring')->assertSuccessful();

    $first = Invoice::withoutGlobalScope('user')->where('recurring_template_id', $template->id)->firstOrFail();
    expect($first->reverse_charge)->toBeFalse();

    $client->update(['vat_id' => 'DE123456789']);
    $template->refresh()->update(['next_run_date' => today()]);

    $this->artisan('qasa:invoices:generate-recurring')->assertSuccessful();

    $second = Invoice::withoutGlobalScope('user')
        ->where('recurring_template_id', $template->id)
        ->where('id', '!=', $first->id)
        ->firstOrFail();

    expect($second->reverse_charge)->toBeTrue()
        ->and($second->reverse_charge_mode?->value)->toBe('eu')
        ->and((float) $second->items->first()?->vat_rate)->toBe(0.0);
});

it('leaves the schedule untouched and does not crash the scheduler when reverse charge becomes invalid', function (): void {
    $user = rcTemplateOwner();
    $client = Client::factory()->create(['user_id' => $user->id, 'country' => 'SK', 'reverse_charge_allowed' => true]);

    $template = RecurringInvoiceTemplate::factory()->dueToday()->create([
        'user_id' => $user->id,
        'client_id' => $client->id,
        'currency' => 'EUR',
        'due_days' => 14,
        'reverse_charge' => true,
    ]);
    $template->items()->create([
        'description' => 'Služby', 'quantity' => 1, 'unit' => 'ks',
        'unit_price' => 1000, 'vat_rate' => 23, 'sort_order' => 0,
    ]);
    $originalNextRunDate = $template->next_run_date;

    // The client withdraws its domestic reverse-charge opt-in before the
    // template's next run.
    $client->update(['reverse_charge_allowed' => false]);

    $this->artisan('qasa:invoices:generate-recurring')->assertFailed();

    expect(Invoice::withoutGlobalScope('user')->where('recurring_template_id', $template->id)->count())->toBe(0)
        ->and($template->refresh()->next_run_date->toDateString())->toBe($originalNextRunDate->toDateString());
});
