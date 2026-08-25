<?php

declare(strict_types=1);

namespace App\Modules\Shared\Domain\Contracts;

/**
 * The account's own switches over what the application does with its
 * invoices on its behalf, as opposed to what its plan allows.
 *
 * All five answer the account owner's row — a team member does not have a
 * separate reminder cadence — which is the whole reason they are asked
 * through a contract rather than read as columns: the scheduled commands that
 * need them run outside any request and used to name the auth model just to
 * reach `$owner->auto_remind_after_days`.
 */
interface ProvidesInvoicingPreferences
{
    /** Whether uploads dropped into the invoice inbox are scanned at all. */
    public function invoiceInboxEnabled(): bool;

    /**
     * Whether the account is told when one of its invoices newly crosses
     * into overdue. Distinct from autoRemindEnabled(), which emails the
     * client rather than the account.
     */
    public function overdueDigestEnabled(): bool;

    public function autoRemindEnabled(): bool;

    /** Days after due_at before the first automatic reminder goes out. */
    public function autoRemindAfterDays(): int;

    /** Cap on total reminders — manual and automatic — for one invoice. */
    public function autoRemindMaxCount(): int;
}
