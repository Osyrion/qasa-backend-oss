<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Domain\Events;

use App\Modules\Invoicing\Domain\ValueObjects\PublicInvoice;

/**
 * An event about an invoice, describing itself to a subscriber that must not
 * hold one.
 *
 * The events keep carrying the model — the audit trail needs a `Model` to
 * record as its subject, and our own listeners work on the aggregate. But a
 * published event whose only accessor is the aggregate publishes the aggregate:
 * anything that subscribes can walk `$event->invoice` into every relation we
 * have, and deptrac cannot see it because a property read names no class.
 *
 * So the event answers instead. {@see PublicInvoice} is the value it answers
 * with — the same one the public payment path uses, because a subscriber
 * outside the module is in the same position as a stranger holding a link:
 * entitled to know what happened to which document, and to nothing about the
 * customer it was issued to.
 */
interface CarriesInvoice
{
    /**
     * Named apart from the `$invoice` property on purpose: `$event->invoice`
     * is the aggregate, for us, and `$event->publicInvoice()` is the value,
     * for everyone else. One name for both would make which of the two a call
     * site holds a matter of a pair of parentheses.
     */
    public function publicInvoice(): PublicInvoice;
}
