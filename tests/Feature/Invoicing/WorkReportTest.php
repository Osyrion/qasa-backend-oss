<?php

declare(strict_types=1);

use App\Modules\Auth\Domain\Models\User;
use App\Modules\Clients\Domain\Models\Client;
use App\Modules\Invoicing\Domain\Models\Invoice;
use App\Modules\Orders\Domain\Models\Order;
use App\Modules\TimeTracking\Domain\Models\TimeEntry;

/** @return array{0: User, 1: Invoice} */
function workReportScope(string $status = 'draft'): array
{
    $user = createUser();
    $client = Client::factory()->create(['user_id' => $user->id]);

    $invoice = Invoice::factory()->create([
        'user_id' => $user->id,
        'client_id' => $client->id,
        'status' => $status,
        'currency' => 'EUR',
    ]);

    return [$user, $invoice];
}

it('prefills the work report from time-entry backed items', function (): void {
    [$user, $invoice] = workReportScope();

    $order = Order::factory()->create(['user_id' => $user->id, 'client_id' => $invoice->client_id]);

    $entry = TimeEntry::factory()->create([
        'user_id' => $user->id,
        'order_id' => $order->id,
        'started_at' => now()->subDays(3)->setTime(9, 0),
        'ended_at' => now()->subDays(3)->setTime(11, 0),
        'duration_seconds' => 7200,
    ]);

    $invoice->items()->create([
        'time_entry_id' => $entry->id,
        'description' => 'Vícepráce – konzultace',
        'quantity' => 2,
        'unit' => 'hod',
        'unit_price' => 50,
        'vat_rate' => 20,
        'vat_amount' => 20,
        'total_excl_vat' => 100,
        'total_incl_vat' => 120,
        'sort_order' => 0,
    ]);
    $invoice->items()->create([
        'description' => 'Manuálna položka bez výkazu',
        'quantity' => 1,
        'unit' => 'ks',
        'unit_price' => 10,
        'vat_rate' => 0,
        'vat_amount' => 0,
        'total_excl_vat' => 10,
        'total_incl_vat' => 10,
        'sort_order' => 1,
    ]);

    $response = $this->actingAs($user)->postJson("/api/v1/invoices/{$invoice->id}/work-report/generate");

    $response->assertOk();

    expect($response->json())->toHaveCount(1)
        ->and($response->json('0.description'))->toBe('Vícepráce – konzultace')
        ->and((float) $response->json('0.hours'))->toBe(2.0)
        ->and($response->json('0.work_date'))->toBe(now()->subDays(3)->toDateString());
});

it('replaces work report lines via bulk PUT', function (): void {
    [$user, $invoice] = workReportScope();

    $invoice->workReportLines()->create([
        'work_date' => today()->toDateString(),
        'description' => 'Old line',
        'hours' => 1,
        'sort_order' => 0,
    ]);

    $response = $this->actingAs($user)->putJson("/api/v1/invoices/{$invoice->id}/work-report", [
        'lines' => [
            ['work_date' => today()->subDay()->toDateString(), 'description' => 'Edited A', 'hours' => 1.5],
            ['work_date' => today()->toDateString(), 'description' => 'Edited B', 'hours' => 2],
        ],
    ]);

    $response->assertOk();

    expect($response->json())->toHaveCount(2)
        ->and($response->json('0.description'))->toBe('Edited A')
        ->and($invoice->workReportLines()->count())->toBe(2);
});

it('lists work report lines', function (): void {
    [$user, $invoice] = workReportScope('sent');

    $invoice->workReportLines()->create([
        'work_date' => today()->toDateString(),
        'description' => 'Line',
        'hours' => 3,
        'sort_order' => 0,
    ]);

    $this->actingAs($user)->getJson("/api/v1/invoices/{$invoice->id}/work-report")
        ->assertOk()
        ->assertJsonCount(1, 'data');
});

it('rejects editing the work report of a non-draft invoice', function (): void {
    [$user, $invoice] = workReportScope('sent');

    $this->actingAs($user)->putJson("/api/v1/invoices/{$invoice->id}/work-report", [
        'lines' => [],
    ])->assertForbidden();

    $this->actingAs($user)->postJson("/api/v1/invoices/{$invoice->id}/work-report/generate")
        ->assertForbidden();
});
