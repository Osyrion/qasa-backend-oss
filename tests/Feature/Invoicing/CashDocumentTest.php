<?php

declare(strict_types=1);

use App\Modules\Auth\Domain\Models\User;
use App\Modules\Clients\Domain\Models\Client;
use App\Modules\Invoicing\Domain\Enums\CashDocumentType;
use App\Modules\Invoicing\Domain\Models\CashDocument;
use App\Modules\Invoicing\Domain\Models\Expense;
use App\Modules\Invoicing\Domain\Models\Invoice;
use App\Modules\Invoicing\Domain\Models\InvoicePayment;
use App\Modules\Taxation\Application\Services\TaxIncomeAggregator;
use App\Modules\Taxation\Domain\Enums\TaxResidency;
use App\Modules\Taxation\Domain\ValueObjects\SystemIncomeData;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;

/**
 * @param  array<string, mixed>  $payload
 * @return TestResponse<JsonResponse>
 */
function postCashDocument(User $owner, array $payload): TestResponse
{
    return test()->actingAs($owner)->postJson('/api/v1/cash-documents', [
        'type' => 'income',
        'issued_at' => '2026-03-10',
        'amount' => 120.00,
        'currency' => 'EUR',
        'description' => 'Hotovostný predaj',
        ...$payload,
    ]);
}

it('numbers receipts and payments in separate series per year', function (): void {
    $owner = createUser();

    $first = postCashDocument($owner, [])->assertCreated()->json('data.number');
    $second = postCashDocument($owner, [])->assertCreated()->json('data.number');
    $expense = postCashDocument($owner, ['type' => 'expense'])->assertCreated()->json('data.number');

    expect($first)->toBe('PPD-2026-001')
        ->and($second)->toBe('PPD-2026-002')
        ->and($expense)->toBe('VPD-2026-001');
});

it('rejects a negative amount rather than treating it as the other direction', function (): void {
    $owner = createUser();

    // Direction is the type's job; a signed amount would be a second,
    // contradictory way to say the same thing.
    postCashDocument($owner, ['amount' => -10])->assertStatus(422);
});

it('refuses to link a payment belonging to another account', function (): void {
    $owner = createUser();
    $stranger = createUser();

    $payment = asAccount($stranger, function () use ($stranger): InvoicePayment {
        $client = Client::factory()->create(['user_id' => $stranger->id]);
        $invoice = Invoice::factory()->create(['user_id' => $stranger->id, 'client_id' => $client->id]);

        return InvoicePayment::factory()->create(['invoice_id' => $invoice->id]);
    });

    // Stored, it would be invisible under the policy but would still flip
    // the document to "already recorded" and silently drop it from the tax
    // figures — a 422 now beats a wrong tax return later.
    postCashDocument($owner, ['invoice_payment_id' => $payment->id])->assertStatus(422);
});

it('never exposes another account cash documents', function (): void {
    $owner = createUser();
    postCashDocument($owner, [])->assertCreated();

    $stranger = createUser();

    expect(asAccount($stranger, fn (): int => DB::table('cash_documents')->count()))->toBe(0)
        ->and(asAccount($owner, fn (): int => DB::table('cash_documents')->count()))->toBe(1);
});

// ── Reversal ─────────────────────────────────────────────────────────────────

it('corrects a mistake by reversal, leaving both documents in the book', function (): void {
    $owner = createUser();

    $id = postCashDocument($owner, [])->assertCreated()->json('data.id');

    $reversal = $this->actingAs($owner)
        ->postJson("/api/v1/cash-documents/{$id}/reverse")
        ->assertCreated()
        ->json('data');

    expect($reversal['type'])->toBe('expense')
        ->and($reversal['number'])->toBe('VPD-2026-001')
        // Dated to the original: a correction belongs to the period the
        // mistake was made in, or the balance is wrong on both dates.
        ->and($reversal['issued_at'])->toBe('2026-03-10')
        ->and($reversal['reverses_cash_document_id'])->toBe($id);

    $book = $this->actingAs($owner)->getJson('/api/v1/cash-book')->assertOk()->json('data');

    expect($book['entries'])->toHaveCount(2)
        ->and($book['closing_balances']['EUR'])->toBe('0.00');
});

it('reverses a document at most once, and never reverses a reversal', function (): void {
    $owner = createUser();

    $id = postCashDocument($owner, [])->assertCreated()->json('data.id');
    $reversalId = $this->actingAs($owner)->postJson("/api/v1/cash-documents/{$id}/reverse")
        ->assertCreated()->json('data.id');

    $this->actingAs($owner)->postJson("/api/v1/cash-documents/{$id}/reverse")->assertStatus(422);
    $this->actingAs($owner)->postJson("/api/v1/cash-documents/{$reversalId}/reverse")->assertStatus(422);
});

it('offers no way to edit or delete a cash document', function (): void {
    $owner = createUser();
    $id = postCashDocument($owner, [])->assertCreated()->json('data.id');

    // An accounting record that can be silently rewritten is not one.
    $this->actingAs($owner)->putJson("/api/v1/cash-documents/{$id}", ['amount' => 1])->assertStatus(405);
    $this->actingAs($owner)->deleteJson("/api/v1/cash-documents/{$id}")->assertStatus(405);
});

// ── Cash book ────────────────────────────────────────────────────────────────

it('runs a balance per currency, in date order', function (): void {
    $owner = createUser();

    postCashDocument($owner, ['issued_at' => '2026-03-01', 'amount' => 100])->assertCreated();
    postCashDocument($owner, ['issued_at' => '2026-03-05', 'amount' => 30, 'type' => 'expense'])->assertCreated();
    postCashDocument($owner, ['issued_at' => '2026-03-07', 'amount' => 50, 'currency' => 'CZK'])->assertCreated();

    $book = $this->actingAs($owner)->getJson('/api/v1/cash-book')->assertOk()->json('data');

    // Currencies are kept apart: one balance across both would be a number
    // that means nothing.
    expect($book['entries'][0]['balance'])->toBe('100.00')
        ->and($book['entries'][1]['balance'])->toBe('70.00')
        ->and($book['entries'][2]['balance'])->toBe('50.00')
        ->and($book['closing_balances'])->toBe(['EUR' => '70.00', 'CZK' => '50.00']);
});

it('carries the balance into a filtered period as an opening balance', function (): void {
    $owner = createUser();

    postCashDocument($owner, ['issued_at' => '2026-02-01', 'amount' => 200])->assertCreated();
    postCashDocument($owner, ['issued_at' => '2026-03-01', 'amount' => 50])->assertCreated();

    $book = $this->actingAs($owner)
        ->getJson('/api/v1/cash-book?from=2026-03-01')
        ->assertOk()->json('data');

    expect($book['opening_balances']['EUR'])->toBe('200.00')
        ->and($book['entries'])->toHaveCount(1)
        ->and($book['entries'][0]['balance'])->toBe('250.00');
});

// ── The tax chain ────────────────────────────────────────────────────────────

function aggregateFor(User $owner): SystemIncomeData
{
    return app(TaxIncomeAggregator::class)->aggregate($owner, 2026, TaxResidency::Sk);
}

it('counts a standalone cash receipt as business income', function (): void {
    $owner = createUser(['country' => 'SK']);

    $before = aggregateFor($owner)->businessIncome;

    postCashDocument($owner, ['amount' => 120])->assertCreated();

    expect(aggregateFor($owner)->businessIncome)->toBe($before + 120.0);
});

it('does not count a receipt that only documents an already-recorded payment', function (): void {
    $owner = createUser(['country' => 'SK']);
    $client = Client::factory()->create(['user_id' => $owner->id]);
    $invoice = Invoice::factory()->create(['user_id' => $owner->id, 'client_id' => $client->id, 'currency' => 'EUR']);

    $payment = InvoicePayment::factory()->create([
        'invoice_id' => $invoice->id,
        'amount' => 120,
        'paid_at' => '2026-03-10',
        'method' => 'cash',
    ]);

    $withPaymentOnly = aggregateFor($owner)->businessIncome;

    postCashDocument($owner, ['amount' => 120, 'invoice_payment_id' => $payment->id])->assertCreated();

    // The receipt is a piece of paper for money already counted. Without
    // this distinction every cash-paid invoice would double its income.
    expect(aggregateFor($owner)->businessIncome)->toBe($withPaymentOnly);
});

it('counts a standalone cash payment as an expense, and not one that documents an existing expense', function (): void {
    $owner = createUser(['country' => 'SK']);

    $expense = Expense::factory()->create([
        'user_id' => $owner->id,
        'amount' => 40,
        'currency' => 'EUR',
        'date' => '2026-03-02',
    ]);

    $withExpenseOnly = aggregateFor($owner)->otherExpenses;

    postCashDocument($owner, ['type' => 'expense', 'amount' => 40, 'expense_id' => $expense->id])->assertCreated();
    expect(aggregateFor($owner)->otherExpenses)->toBe($withExpenseOnly);

    postCashDocument($owner, ['type' => 'expense', 'amount' => 15])->assertCreated();
    expect(aggregateFor($owner)->otherExpenses)->toBe($withExpenseOnly + 15.0);
});

it('cancels a reversed receipt out of the tax figures too', function (): void {
    $owner = createUser(['country' => 'SK']);

    $before = aggregateFor($owner)->businessIncome;

    $id = postCashDocument($owner, ['amount' => 90])->assertCreated()->json('data.id');
    expect(aggregateFor($owner)->businessIncome)->toBe($before + 90.0);

    $this->actingAs($owner)->postJson("/api/v1/cash-documents/{$id}/reverse")->assertCreated();

    // The reversal is itself a standalone document of the opposite type, so
    // it nets out without the aggregator needing to know about reversals.
    $after = aggregateFor($owner);

    // The reversal is a standalone expense document, so the *net* of the
    // two is zero without the aggregator knowing what a reversal is.
    expect($after->businessIncome)->toBe($before + 90.0)
        ->and($after->otherExpenses)->toBe(90.0);

    expect(CashDocument::query()->where('type', CashDocumentType::Expense->value)->count())->toBe(1);
});
