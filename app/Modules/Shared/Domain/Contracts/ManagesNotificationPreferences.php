<?php

declare(strict_types=1);

namespace App\Modules\Shared\Domain\Contracts;

use App\Modules\Shared\Enums\NotificationCategory;

/**
 * The write side of ProvidesNotificationPreferences.
 *
 * Split from the read side deliberately: everything that *sends* a
 * notification only ever asks, and giving those callers a setter would be
 * handing out a way to change the account from inside a mail path. Only the
 * preferences endpoint takes this one.
 *
 * A write into the account, which no read model can stand in for — the same
 * shape as ClientBankAccountLearner, named for what it means rather than for
 * the six `notify_*_enabled` columns behind it. Those column names are an API
 * detail of one endpoint, not a concept other modules should learn.
 *
 * Personal, not account-wide: unlike almost everything else on the account,
 * these preferences belong to the individual — a team member silencing
 * billing notifications must not silence the owner's.
 */
interface ManagesNotificationPreferences extends ProvidesNotificationPreferences
{
    /**
     * Set the categories named; leave every other category unchanged.
     *
     * @param  array<string, bool>  $wanted  keyed by NotificationCategory value
     */
    public function updateNotificationPreferences(array $wanted): void;
}
