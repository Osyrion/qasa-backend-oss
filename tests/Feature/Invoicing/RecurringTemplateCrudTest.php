<?php

declare(strict_types=1);

use App\Modules\Auth\Domain\Models\User;
use App\Modules\Clients\Domain\Models\Client;
use App\Modules\Invoicing\Application\Services\VatRateSeederService;
use App\Modules\Invoicing\Domain\Models\RecurringInvoiceTemplate;

/** @return array{0: User, 1: Client} */
function templateScope(): array
{
    $user = createUser(['invoice_prefix' => 'FA', 'country' => 'CZ', 'vat_status' => 'payer']);
    app(VatRateSeederService::class)->seedFor($user);
    $client = Client::factory()->create(['user_id' => $user->id]);

    return [$user, $client];
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function templatePayload(Client $client, array $overrides = []): array
{
    return [
        'name' => 'Měsíční hosting',
        'client_id' => $client->id,
        'period' => 'monthly',
        'day_of_month' => 1,
        'first_issue_date' => today()->addDay()->toDateString(),
        'currency' => 'CZK',
        'due_days' => 14,
        'items' => [
            [
                'description' => 'Hosting {MONTH}',
                'quantity' => 1,
                'unit' => 'ks',
                'unit_price' => 1000,
                'vat_rate' => 21,
            ],
        ],
        ...$overrides,
    ];
}

it('creates a template with items and schedules it to the first issue date', function (): void {
    [$user, $client] = templateScope();

    $response = $this->actingAs($user)->postJson(
        '/api/v1/recurring-invoice-templates',
        templatePayload($client, [
            'note_above' => 'Vyúčtování {BOM} – {EOM}',
            'tax_date_mode' => 'previous_month_end',
        ]),
    );

    $response->assertCreated();

    expect($response->json('status'))->toBe('active')
        ->and($response->json('next_run_date'))->toBe(today()->addDay()->toDateString())
        ->and($response->json('tax_date_mode'))->toBe('previous_month_end')
        ->and($response->json('items'))->toHaveCount(1)
        ->and($response->json('items.0.description'))->toBe('Hosting {MONTH}');
});

it('rejects invalid payloads', function (array $overrides): void {
    [$user, $client] = templateScope();

    $this->actingAs($user)
        ->postJson('/api/v1/recurring-invoice-templates', templatePayload($client, $overrides))
        ->assertUnprocessable();
})->with([
    'day_of_month 0' => [['day_of_month' => 0]],
    'day_of_month 29' => [['day_of_month' => 29]],
    'no items' => [['items' => []]],
    // Date datasets must be lazy closures: eager values are built at file load
    // in the CLI default timezone, which disagrees with the app's UTC around
    // midnight and turns "yesterday" into "today".
    'past first_issue_date' => [fn (): array => ['first_issue_date' => today()->subDay()->toDateString()]],
    'end_date before first_issue_date' => [fn (): array => ['end_date' => today()->toDateString()]],
    'credit_note type' => [['type' => 'credit_note']],
    'discount over 100' => [['discount_percent' => 101]],
    'non-boolean auto_send' => [['auto_send' => 'maybe']],
]);

it('persists the auto_send flag on create and update', function (): void {
    [$user, $client] = templateScope();

    $response = $this->actingAs($user)->postJson(
        '/api/v1/recurring-invoice-templates',
        templatePayload($client, ['auto_send' => true]),
    );

    $response->assertCreated();
    expect($response->json('auto_send'))->toBeTrue();

    $templateId = $response->json('id');

    $response = $this->actingAs($user)->putJson(
        "/api/v1/recurring-invoice-templates/{$templateId}",
        templatePayload($client, ['auto_send' => false]),
    );

    $response->assertOk();
    expect($response->json('auto_send'))->toBeFalse();
});

it('rejects a client belonging to another account', function (): void {
    [$user] = templateScope();
    $foreignClient = Client::factory()->create(['user_id' => createUser()->id]);

    $this->actingAs($user)
        ->postJson('/api/v1/recurring-invoice-templates', templatePayload($foreignClient))
        ->assertUnprocessable();
});

it('updates a template, replaces items and follows first_issue_date when never generated', function (): void {
    [$user, $client] = templateScope();

    $template = RecurringInvoiceTemplate::factory()->create([
        'user_id' => $user->id,
        'client_id' => $client->id,
        'first_issue_date' => today()->addDays(5)->toDateString(),
        'next_run_date' => today()->addDays(5)->toDateString(),
    ]);
    $template->items()->create([
        'description' => 'Stará položka', 'quantity' => 1, 'unit' => 'ks',
        'unit_price' => 500, 'vat_rate' => 0, 'sort_order' => 0,
    ]);

    $newDate = today()->addDays(10)->toDateString();

    $response = $this->actingAs($user)->putJson(
        "/api/v1/recurring-invoice-templates/{$template->id}",
        templatePayload($client, ['first_issue_date' => $newDate]),
    );

    $response->assertOk();

    expect($response->json('next_run_date'))->toBe($newDate)
        ->and($response->json('items'))->toHaveCount(1)
        ->and($response->json('items.0.description'))->toBe('Hosting {MONTH}');
});

it('recomputes the schedule from last_generated_at when periodicity changes', function (): void {
    [$user, $client] = templateScope();

    $lastGenerated = today()->subDays(10);
    $template = RecurringInvoiceTemplate::factory()->create([
        'user_id' => $user->id,
        'client_id' => $client->id,
        'period' => 'monthly',
        'day_of_month' => 5,
        'first_issue_date' => $lastGenerated->toDateString(),
        'last_generated_at' => $lastGenerated->toDateString(),
        'next_run_date' => $lastGenerated->copy()->addMonth()->toDateString(),
    ]);

    $response = $this->actingAs($user)->putJson(
        "/api/v1/recurring-invoice-templates/{$template->id}",
        templatePayload($client, [
            'period' => 'quarterly',
            'day_of_month' => 5,
            'first_issue_date' => $lastGenerated->toDateString(),
        ]),
    );

    $response->assertOk();

    $expected = $lastGenerated->toImmutable()->startOfMonth()->addMonths(3)->setDay(5);

    expect($response->json('next_run_date'))->toBe($expected->toDateString());
});

it('pauses and resumes a template, fast-forwarding past skipped periods', function (): void {
    [$user, $client] = templateScope();

    $template = RecurringInvoiceTemplate::factory()->dueToday()->create([
        'user_id' => $user->id,
        'client_id' => $client->id,
    ]);

    $this->actingAs($user)
        ->postJson("/api/v1/recurring-invoice-templates/{$template->id}/pause")
        ->assertOk()
        ->assertJsonPath('status', 'paused');

    // Simulate a long pause: schedule left far in the past.
    $template->refresh();
    $template->next_run_date = today()->subMonths(3)->toImmutable();
    $template->save();

    $response = $this->actingAs($user)
        ->postJson("/api/v1/recurring-invoice-templates/{$template->id}/resume")
        ->assertOk();

    expect($response->json('status'))->toBe('active')
        ->and($response->json('next_run_date'))->toBeGreaterThanOrEqual(today()->toDateString());
});

it('rejects invalid state transitions with 422', function (): void {
    [$user, $client] = templateScope();

    $paused = RecurringInvoiceTemplate::factory()->paused()->create([
        'user_id' => $user->id, 'client_id' => $client->id,
    ]);

    $this->actingAs($user)
        ->postJson("/api/v1/recurring-invoice-templates/{$paused->id}/pause")
        ->assertUnprocessable();

    $active = RecurringInvoiceTemplate::factory()->create([
        'user_id' => $user->id, 'client_id' => $client->id,
    ]);

    $this->actingAs($user)
        ->postJson("/api/v1/recurring-invoice-templates/{$active->id}/resume")
        ->assertUnprocessable();
});

it('deletes a template', function (): void {
    [$user, $client] = templateScope();

    $template = RecurringInvoiceTemplate::factory()->create([
        'user_id' => $user->id, 'client_id' => $client->id,
    ]);

    $this->actingAs($user)
        ->deleteJson("/api/v1/recurring-invoice-templates/{$template->id}")
        ->assertNoContent();

    expect(RecurringInvoiceTemplate::withoutGlobalScope('user')->find($template->id))->toBeNull()
        ->and(RecurringInvoiceTemplate::withoutGlobalScope('user')->withTrashed()->find($template->id))
        ->not->toBeNull();
});

it('hides templates of other accounts', function (): void {
    [$user, $client] = templateScope();
    $template = RecurringInvoiceTemplate::factory()->create([
        'user_id' => $user->id, 'client_id' => $client->id,
    ]);

    $stranger = createUser();

    $this->actingAs($stranger)
        ->getJson("/api/v1/recurring-invoice-templates/{$template->id}")
        ->assertNotFound();

    $list = $this->actingAs($stranger)
        ->getJson('/api/v1/recurring-invoice-templates')
        ->assertOk();

    expect($list->json('data'))->toBeEmpty();
});

it('lists templates ordered by next_run_date with status filter', function (): void {
    [$user, $client] = templateScope();

    RecurringInvoiceTemplate::factory()->create([
        'user_id' => $user->id, 'client_id' => $client->id,
        'next_run_date' => today()->addDays(20)->toDateString(),
    ]);
    RecurringInvoiceTemplate::factory()->create([
        'user_id' => $user->id, 'client_id' => $client->id,
        'next_run_date' => today()->addDays(5)->toDateString(),
    ]);
    RecurringInvoiceTemplate::factory()->paused()->create([
        'user_id' => $user->id, 'client_id' => $client->id,
    ]);

    $all = $this->actingAs($user)->getJson('/api/v1/recurring-invoice-templates')->assertOk();
    $active = $this->actingAs($user)
        ->getJson('/api/v1/recurring-invoice-templates?status=active')
        ->assertOk();

    expect($all->json('data'))->toHaveCount(3)
        ->and($active->json('data'))->toHaveCount(2)
        ->and($active->json('data.0.next_run_date'))
        ->toBeLessThanOrEqual($active->json('data.1.next_run_date'));
});
