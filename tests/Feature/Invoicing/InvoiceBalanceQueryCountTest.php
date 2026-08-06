<?php

declare(strict_types=1);

use App\Modules\Clients\Domain\Models\Client;
use App\Modules\Invoicing\Domain\Models\Invoice;
use App\Modules\Invoicing\Domain\Models\InvoiceItem;
use App\Modules\Invoicing\Domain\Models\InvoicePayment;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;

/**
 * Invoice::balance() runs `sum(amount)` over the payments every time it is
 * called, and InvoiceResource calls it twice per row (balance +
 * payment_status). The repository already asks for withSum('payments') —
 * the aggregate was being computed for the whole page and then thrown away,
 * so a page of 15 invoices issued 30 aggregate queries it had already paid
 * for.
 */
function countPaymentSumQueries(callable $work): int
{
    $count = 0;

    DB::listen(function (QueryExecuted $query) use (&$count): void {
        if (str_contains($query->sql, 'from "invoice_payments"') && str_contains($query->sql, 'sum(')) {
            $count++;
        }
    });

    $work();

    return $count;
}

it('does not re-aggregate payments per row on the invoice listing', function (): void {
    $user = createUser();
    $this->actingAs($user);

    $client = Client::factory()->create(['user_id' => $user->id]);

    foreach (range(1, 15) as $ignored) {
        $invoice = Invoice::factory()->create(['user_id' => $user->id, 'client_id' => $client->id]);
        InvoiceItem::factory()->count(2)->create(['invoice_id' => $invoice->id]);
        InvoicePayment::factory()->create(['invoice_id' => $invoice->id]);
    }

    $queries = countPaymentSumQueries(function (): void {
        $this->getJson('/api/v1/invoices?per_page=15')->assertOk()->assertJsonCount(15, 'data');
    });

    // The one withSum() the repository already asks for, and nothing else.
    expect($queries)->toBe(1);
});

it('still reports the right balance from the eager-loaded aggregate', function (): void {
    $user = createUser();
    $this->actingAs($user);

    $client = Client::factory()->create(['user_id' => $user->id]);
    $invoice = Invoice::factory()->create([
        'user_id' => $user->id,
        'client_id' => $client->id,
        'subtotal' => 100,
        'vat_amount' => 0,
        'total' => 100,
    ]);
    InvoicePayment::factory()->create(['invoice_id' => $invoice->id, 'amount' => 30]);
    InvoicePayment::factory()->create(['invoice_id' => $invoice->id, 'amount' => 20.55]);

    $this->getJson('/api/v1/invoices?per_page=15')
        ->assertOk()
        ->assertJsonPath('data.0.balance', 49.45)
        ->assertJsonPath('data.0.payment_status', 'partial');
});

it('recomputes the balance after a payment is recorded on an already-aggregated invoice', function (): void {
    $user = createUser();
    $client = Client::factory()->create(['user_id' => $user->id]);

    $invoice = Invoice::factory()->create([
        'user_id' => $user->id,
        'client_id' => $client->id,
        'subtotal' => 100,
        'vat_amount' => 0,
        'total' => 100,
    ]);
    InvoicePayment::factory()->create(['invoice_id' => $invoice->id, 'amount' => 40]);

    // Loaded the way a listing loads it — the aggregate is cached on the model.
    $loaded = Invoice::query()->withSum('payments', 'amount')->findOrFail($invoice->id);
    expect($loaded->balance())->toBe(60.0);

    InvoicePayment::factory()->create(['invoice_id' => $invoice->id, 'amount' => 60]);

    // A stale cached aggregate here would report 60 forever.
    $loaded->unsetRelation('payments')->forgetPaymentsAggregate();

    expect($loaded->balance())->toBe(0.0);
});

it('reports the full total when the eager aggregate is null (no payments yet)', function (): void {
    $user = createUser();
    $client = Client::factory()->create(['user_id' => $user->id]);
    $invoice = Invoice::factory()->create([
        'user_id' => $user->id,
        'client_id' => $client->id,
        'subtotal' => 100,
        'vat_amount' => 0,
        'total' => 100,
    ]);

    // sum() over no rows is SQL NULL, so the attribute exists and is null —
    // "loaded, and the answer is nothing", not "not loaded".
    $loaded = Invoice::query()->withSum('payments', 'amount')->findOrFail($invoice->id);

    expect($loaded->hasPaymentsAggregate())->toBeTrue()
        ->and($loaded->getAttribute(Invoice::PAYMENTS_SUM))->toBeNull()
        ->and($loaded->balance())->toBe(100.0);
});
