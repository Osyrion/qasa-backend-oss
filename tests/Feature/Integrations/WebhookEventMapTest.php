<?php

declare(strict_types=1);

use App\Modules\Clients\Domain\Models\Client;
use App\Modules\Integrations\Application\Webhooks\WebhookEventMap;
use App\Modules\Invoicing\Domain\Events\InboxItemCreated;
use App\Modules\Invoicing\Domain\Events\InvoiceCreated;
use App\Modules\Invoicing\Domain\Events\InvoiceOverdue;
use App\Modules\Invoicing\Domain\Events\InvoicePaid;
use App\Modules\Invoicing\Domain\Events\InvoiceReminded;
use App\Modules\Invoicing\Domain\Events\InvoiceSent;
use App\Modules\Invoicing\Domain\Events\PaymentRecorded;
use App\Modules\Invoicing\Domain\Models\Invoice;
use App\Modules\Invoicing\Domain\Models\InvoiceInboxItem;

it('exposes exactly the catalogued wire events', function (): void {
    expect(WebhookEventMap::allWireEvents())->toEqualCanonicalizing([
        'invoice.created',
        'invoice.sent',
        'invoice.paid',
        'invoice.reminded',
        'invoice.overdue',
        'payment.recorded',
        'inbox.item_created',
    ]);
});

it('maps every invoice lifecycle event to a thin invoice payload', function (): void {
    $user = createUser();
    $client = Client::factory()->create(['user_id' => $user->id]);
    $invoice = Invoice::factory()->create(['user_id' => $user->id, 'client_id' => $client->id]);

    $cases = [
        [new InvoiceCreated($invoice), 'invoice.created'],
        [new InvoiceSent($invoice), 'invoice.sent'],
        [new InvoicePaid($invoice), 'invoice.paid'],
        [new InvoiceReminded($invoice), 'invoice.reminded'],
        [new InvoiceOverdue($invoice), 'invoice.overdue'],
    ];

    foreach ($cases as [$event, $wireName]) {
        expect(WebhookEventMap::wireNameFor($event))->toBe($wireName)
            ->and(WebhookEventMap::ownerIdFor($event))->toBe($user->id)
            ->and(WebhookEventMap::payloadFor($event))->toMatchArray([
                'id' => $invoice->id,
                'invoice_number' => $invoice->invoice_number,
                'status' => $invoice->status,
            ]);
    }
});

it('maps PaymentRecorded to a payload with both invoice and payment identifiers', function (): void {
    $user = createUser();
    $client = Client::factory()->create(['user_id' => $user->id]);
    $invoice = Invoice::factory()->create(['user_id' => $user->id, 'client_id' => $client->id]);
    $payment = $invoice->payments()->create([
        'amount' => 100,
        'paid_at' => now(),
        'method' => 'bank_transfer',
    ]);

    $event = new PaymentRecorded($invoice, $payment);

    expect(WebhookEventMap::wireNameFor($event))->toBe('payment.recorded')
        ->and(WebhookEventMap::ownerIdFor($event))->toBe($user->id)
        ->and(WebhookEventMap::payloadFor($event))->toMatchArray([
            'invoice_id' => $invoice->id,
            'payment_id' => $payment->id,
        ]);
});

it('maps InboxItemCreated to a payload identifying the inbox item', function (): void {
    $user = createUser();
    $item = InvoiceInboxItem::factory()->create(['user_id' => $user->id]);

    $event = new InboxItemCreated($item);

    expect(WebhookEventMap::wireNameFor($event))->toBe('inbox.item_created')
        ->and(WebhookEventMap::ownerIdFor($event))->toBe($user->id)
        ->and(WebhookEventMap::payloadFor($event))->toMatchArray([
            'id' => $item->id,
            'original_filename' => $item->original_filename,
        ]);
});

it('returns null/empty for an event outside the catalogue', function (): void {
    $event = new stdClass;

    expect(WebhookEventMap::wireNameFor($event))->toBeNull()
        ->and(WebhookEventMap::ownerIdFor($event))->toBeNull()
        ->and(WebhookEventMap::payloadFor($event))->toBe([]);
});
