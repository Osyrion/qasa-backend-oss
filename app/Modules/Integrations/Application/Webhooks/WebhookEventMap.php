<?php

declare(strict_types=1);

namespace App\Modules\Integrations\Application\Webhooks;

use App\Modules\Invoicing\Domain\Events\InboxItemCreated;
use App\Modules\Invoicing\Domain\Events\InvoiceCreated;
use App\Modules\Invoicing\Domain\Events\InvoiceOverdue;
use App\Modules\Invoicing\Domain\Events\InvoicePaid;
use App\Modules\Invoicing\Domain\Events\InvoiceReminded;
use App\Modules\Invoicing\Domain\Events\InvoiceSent;
use App\Modules\Invoicing\Domain\Events\PaymentRecorded;
use App\Modules\Invoicing\Domain\Models\Invoice;

/**
 * Single source of truth mapping domain events to webhook wire names and
 * their (thin) payload shape — consumers fetch full detail via the API.
 */
final class WebhookEventMap
{
    /**
     * @return array<class-string, string>
     */
    public static function wireNames(): array
    {
        return [
            InvoiceCreated::class => 'invoice.created',
            InvoiceSent::class => 'invoice.sent',
            InvoicePaid::class => 'invoice.paid',
            InvoiceReminded::class => 'invoice.reminded',
            InvoiceOverdue::class => 'invoice.overdue',
            PaymentRecorded::class => 'payment.recorded',
            InboxItemCreated::class => 'inbox.item_created',
        ];
    }

    /**
     * @return list<string>
     */
    public static function allWireEvents(): array
    {
        return array_values(self::wireNames());
    }

    public static function wireNameFor(object $event): ?string
    {
        return self::wireNames()[$event::class] ?? null;
    }

    public static function ownerIdFor(object $event): ?string
    {
        return match (true) {
            $event instanceof PaymentRecorded => $event->invoice->user_id,
            $event instanceof InboxItemCreated => $event->item->user_id,
            $event instanceof InvoiceCreated => $event->invoice->user_id,
            $event instanceof InvoiceSent => $event->invoice->user_id,
            $event instanceof InvoicePaid => $event->invoice->user_id,
            $event instanceof InvoiceReminded => $event->invoice->user_id,
            $event instanceof InvoiceOverdue => $event->invoice->user_id,
            default => null,
        };
    }

    /**
     * @return array<string, mixed>
     */
    public static function payloadFor(object $event): array
    {
        return match (true) {
            $event instanceof PaymentRecorded => [
                'invoice_id' => $event->invoice->id,
                'invoice_number' => $event->invoice->invoice_number,
                'payment_id' => $event->payment->id,
                'amount' => (string) $event->payment->amount,
                'paid_at' => $event->payment->paid_at->toISOString(),
            ],
            $event instanceof InboxItemCreated => [
                'id' => $event->item->id,
                'original_filename' => $event->item->original_filename,
                'status' => $event->item->status,
            ],
            $event instanceof InvoiceCreated => self::invoicePayload($event->invoice),
            $event instanceof InvoiceSent => self::invoicePayload($event->invoice),
            $event instanceof InvoicePaid => self::invoicePayload($event->invoice),
            $event instanceof InvoiceReminded => self::invoicePayload($event->invoice),
            $event instanceof InvoiceOverdue => self::invoicePayload($event->invoice),
            default => [],
        };
    }

    /**
     * @return array<string, mixed>
     */
    private static function invoicePayload(Invoice $invoice): array
    {
        return [
            'id' => $invoice->id,
            'invoice_number' => $invoice->invoice_number,
            'status' => $invoice->status,
            'total' => (string) $invoice->total,
            'currency' => $invoice->currency->value,
        ];
    }
}
