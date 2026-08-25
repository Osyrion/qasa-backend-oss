<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Domain\ValueObjects;

use App\Modules\Invoicing\Domain\Enums\ReminderResult;

/**
 * One line of a reminder run's result — what happened, and to which document.
 *
 * The number rather than the id on purpose: the only consumer is a console
 * command reporting what it did, and an id tells the reader nothing. It is
 * null for a draft that has not been numbered yet, which cannot actually
 * happen in a reminder run but is what the column allows.
 */
final readonly class ReminderOutcome
{
    public function __construct(
        public ReminderResult $result,
        public ?string $documentNumber,
    ) {}
}
