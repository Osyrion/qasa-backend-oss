<?php

declare(strict_types=1);

namespace App\Modules\Shared\Infrastructure\Notifications;

use App\Modules\Auth\Domain\Models\User;
use Illuminate\Notifications\Channels\DatabaseChannel;
use Illuminate\Notifications\Notification;
use RuntimeException;

/**
 * Laravel's database channel, plus the account.
 *
 * `notifications.user_id` is the account owner and both HasUserScope and the
 * RLS policy key on it, so a row written without it is not merely untidy —
 * the INSERT is rejected outright. The channel is where this belongs because
 * it is the only place that already holds the notifiable; a model `creating`
 * hook would have to re-read the recipient from the database on every write
 * just to learn what the caller already knew.
 *
 * Replaces the framework's `database` driver via Notification::extend() in
 * SharedServiceProvider — not a new channel name, so `via()` returning
 * 'database' keeps meaning the obvious thing.
 */
class AccountDatabaseChannel extends DatabaseChannel
{
    /**
     * @param  mixed  $notifiable
     * @return array<string, mixed>
     */
    protected function buildPayload($notifiable, Notification $notification): array
    {
        /** @var array<string, mixed> $payload */
        $payload = parent::buildPayload($notifiable, $notification);

        if (! $notifiable instanceof User) {
            // Fail with the reason rather than as a NOT NULL violation three
            // frames down: nothing but a tenant user can own an in-app
            // notification, so this is a wiring mistake, not bad data.
            throw new RuntimeException(sprintf(
                'The database notification channel requires a tenant user, got %s.',
                get_debug_type($notifiable),
            ));
        }

        $payload['user_id'] = $notifiable->accountOwnerId();

        return $payload;
    }
}
