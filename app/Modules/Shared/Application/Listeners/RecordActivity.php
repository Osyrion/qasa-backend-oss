<?php

declare(strict_types=1);

namespace App\Modules\Shared\Application\Listeners;

use App\Modules\Clients\Domain\Events\ClientCreated;
use App\Modules\Clients\Domain\Events\ClientDeleted;
use App\Modules\Clients\Domain\Events\ClientUpdated;
use App\Modules\Invoicing\Domain\Events\InvoiceCreated;
use App\Modules\Invoicing\Domain\Events\InvoicePaid;
use App\Modules\Invoicing\Domain\Events\InvoiceReminded;
use App\Modules\Invoicing\Domain\Events\InvoiceSent;
use App\Modules\Invoicing\Domain\Events\PaymentRecorded;
use App\Modules\Invoicing\Domain\Events\QuoteAccepted;
use App\Modules\Invoicing\Domain\Events\QuoteRejected;
use App\Modules\Orders\Domain\Events\OrderCreated;
use App\Modules\Orders\Domain\Events\OrderDeleted;
use App\Modules\Orders\Domain\Events\OrderUpdated;
use App\Modules\Shared\Application\Contracts\ActivityRecorderInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * Single listener for every activity-worthy domain event, same shape as
 * Integrations\Application\Listeners\DispatchWebhooks — one class registered
 * against many event classes rather than 13 near-identical listeners.
 */
final class RecordActivity
{
    public function __construct(private readonly ActivityRecorderInterface $recorder) {}

    /**
     * @return list<class-string>
     */
    public static function events(): array
    {
        return [
            ClientCreated::class,
            ClientUpdated::class,
            ClientDeleted::class,
            OrderCreated::class,
            OrderUpdated::class,
            OrderDeleted::class,
            InvoiceCreated::class,
            InvoiceSent::class,
            InvoicePaid::class,
            InvoiceReminded::class,
            PaymentRecorded::class,
            QuoteAccepted::class,
            QuoteRejected::class,
        ];
    }

    public function handle(object $event): void
    {
        $subject = $this->subjectFor($event);
        $eventName = $this->wireNameFor($event);

        if ($subject === null || $eventName === null) {
            return;
        }

        $userId = $subject->getAttribute('user_id');

        if (! is_string($userId)) {
            return;
        }

        $actorId = auth()->id();

        $this->recorder->record(
            $userId,
            is_string($actorId) ? $actorId : null,
            $subject,
            $eventName,
            $this->changesFor($event),
        );
    }

    private function subjectFor(object $event): ?Model
    {
        return match (true) {
            $event instanceof ClientCreated, $event instanceof ClientUpdated, $event instanceof ClientDeleted => $event->client,
            $event instanceof OrderCreated, $event instanceof OrderUpdated, $event instanceof OrderDeleted => $event->order,
            $event instanceof InvoiceCreated, $event instanceof InvoiceSent, $event instanceof InvoicePaid, $event instanceof InvoiceReminded => $event->invoice,
            // The payment itself has no user_id — it's owned through its
            // invoice, which is also the more natural subject of "money was
            // recorded against this invoice" in an audit feed.
            $event instanceof PaymentRecorded => $event->invoice,
            $event instanceof QuoteAccepted, $event instanceof QuoteRejected => $event->quote,
            default => null,
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function changesFor(object $event): array
    {
        return match (true) {
            $event instanceof PaymentRecorded => [
                'payment_id' => $event->payment->id,
                'amount' => (string) $event->payment->amount,
            ],
            default => [],
        };
    }

    private function wireNameFor(object $event): ?string
    {
        return match (true) {
            $event instanceof ClientCreated => 'client.created',
            $event instanceof ClientUpdated => 'client.updated',
            $event instanceof ClientDeleted => 'client.deleted',
            $event instanceof OrderCreated => 'order.created',
            $event instanceof OrderUpdated => 'order.updated',
            $event instanceof OrderDeleted => 'order.deleted',
            $event instanceof InvoiceCreated => 'invoice.created',
            $event instanceof InvoiceSent => 'invoice.sent',
            $event instanceof InvoicePaid => 'invoice.paid',
            $event instanceof InvoiceReminded => 'invoice.reminded',
            $event instanceof PaymentRecorded => 'payment.recorded',
            $event instanceof QuoteAccepted => 'quote.accepted',
            $event instanceof QuoteRejected => 'quote.rejected',
            default => null,
        };
    }
}
