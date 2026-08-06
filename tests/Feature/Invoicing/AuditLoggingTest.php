<?php

declare(strict_types=1);

use App\Modules\Invoicing\Application\Actions\DeletePaymentAction;
use App\Modules\Invoicing\Domain\Models\CashDocument;
use App\Modules\Invoicing\Domain\Models\Invoice;
use App\Modules\Shared\Domain\Models\ActivityLog;
use Illuminate\Support\Carbon;

it('records an activity entry when a payment is deleted', function (): void {
    $owner = createUser();

    $invoice = Invoice::factory()->sent()->for($owner)->create(['total' => '100.00']);

    $payment = $invoice->payments()->create([
        'amount' => '40.00',
        'paid_at' => Carbon::now()->toDateString(),
        'method' => 'bank_transfer',
    ]);

    app(DeletePaymentAction::class)->execute($invoice, $payment);

    $entry = ActivityLog::where('subject_type', 'invoice')
        ->where('subject_id', $invoice->id)
        ->where('event', 'payment.deleted')
        ->firstOrFail();

    expect($entry->user_id)->toBe($owner->id)
        ->and($entry->changes)->toMatchArray(['payment_id' => $payment->id, 'amount' => '40.00']);
});

it('records an activity entry when a cash document is reversed', function (): void {
    $owner = createUser();

    $id = $this->actingAs($owner)->postJson('/api/v1/cash-documents', [
        'type' => 'income',
        'issued_at' => '2026-03-10',
        'amount' => 120.00,
        'currency' => 'EUR',
        'description' => 'Hotovostny predaj',
    ])->assertCreated()->json('data.id');

    $reversalId = $this->actingAs($owner)
        ->postJson("/api/v1/cash-documents/{$id}/reverse")
        ->assertCreated()
        ->json('data.id');

    $entry = ActivityLog::where('subject_id', $id)
        ->where('event', 'cash_document.reversed')
        ->firstOrFail();

    $reversal = CashDocument::query()->findOrFail($reversalId);

    expect($entry->user_id)->toBe($owner->id)
        ->and($entry->changes)->toMatchArray([
            'amount' => '120.00',
            'currency' => 'EUR',
            'reversal_number' => $reversal->number,
        ]);
});
