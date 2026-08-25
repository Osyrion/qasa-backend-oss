<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Application\Contracts;

use App\Modules\Invoicing\Domain\ValueObjects\ReminderOutcome;
use App\Modules\Shared\Domain\Contracts\Account;
use App\Modules\Shared\Domain\Contracts\ProvidesInvoicingPreferences;
use App\Modules\Shared\Domain\Contracts\ProvidesSupplierProfile;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Translation\HasLocalePreference;

/**
 * One account's automatic payment reminders for a given day.
 *
 * The split with Automation is deliberate: **when** the run happens and
 * **whether this account opted in** is scheduling, which Automation owns;
 * **what a reminder is** — which documents are due, how the cap works, which
 * mail and notification go out, and that reaching the cap is worth telling
 * the owner about once — is invoicing, and none of it should leave this
 * module. Before this contract, the command in Automation held Invoicing's
 * model, its action, its mailable and its notification.
 *
 * The cadence (after how many days, how many times) is read off the account
 * here rather than passed in: it is the same account object either way, and
 * two callers agreeing on the argument order is a worse guarantee than one
 * implementation reading the setting.
 */
interface InvoiceReminderRunner
{
    /**
     * @return list<ReminderOutcome> one entry per document considered
     */
    public function remindOverdue(
        Account&HasLocalePreference&ProvidesInvoicingPreferences&ProvidesSupplierProfile $owner,
        CarbonImmutable $today,
    ): array;
}
