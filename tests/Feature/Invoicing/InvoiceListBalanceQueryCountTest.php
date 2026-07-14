<?php

declare(strict_types=1);

use App\Modules\Clients\Domain\Models\Client;
use App\Modules\Invoicing\Domain\Enums\InvoiceStatus;
use App\Modules\Invoicing\Domain\Models\Invoice;
use App\Modules\Invoicing\Domain\Models\InvoicePayment;
use Illuminate\Support\Facades\DB;

it('does not run a payments query per invoice when listing invoices', function (): void {
    $user = createUser();
    $client = Client::factory()->create(['user_id' => $user->id]);

    foreach (range(1, 5) as $i) {
        $invoice = Invoice::factory()->create([
            'user_id' => $user->id,
            'client_id' => $client->id,
            'status' => InvoiceStatus::Sent->value,
            'total' => 100,
        ]);

        InvoicePayment::factory()->create([
            'invoice_id' => $invoice->id,
            'amount' => 40,
        ]);
    }

    $queries = [];
    DB::listen(function ($query) use (&$queries): void {
        $queries[] = $query->sql;
    });

    $response = $this->actingAs($user)->getJson('/api/v1/invoices');

    $response->assertOk()->assertJsonCount(5, 'data');

    $paymentsQueries = collect($queries)->filter(fn (string $sql): bool => str_contains($sql, 'invoice_payments'));

    // The list query itself does one withSum('payments', ...) subquery —
    // balance() must not add one more per invoice on top of that.
    expect($paymentsQueries)->toHaveCount(1)
        ->and($response->json('data.0.balance'))->toEqual(60.0);
});
