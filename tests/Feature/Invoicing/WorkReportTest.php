<?php

declare(strict_types=1);

use App\Modules\Auth\Domain\Models\User;
use App\Modules\Clients\Domain\Models\Client;
use App\Modules\Invoicing\Domain\Models\Invoice;

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
        ->assertJsonCount(1);
});

it('rejects editing the work report of a non-draft invoice', function (): void {
    [$user, $invoice] = workReportScope('sent');

    $this->actingAs($user)->putJson("/api/v1/invoices/{$invoice->id}/work-report", [
        'lines' => [],
    ])->assertForbidden();
});
