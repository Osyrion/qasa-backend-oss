<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Domain\Enums;

/**
 * What happened to one invoice in an automatic reminder run.
 *
 * `Exhausted` is not a failure: the cap was reached, so the account was told
 * once that the automation has stopped chasing this document rather than
 * letting it drop out of the flow silently.
 */
enum ReminderResult
{
    case Sent;
    case Skipped;
    case Exhausted;
    case Failed;
}
