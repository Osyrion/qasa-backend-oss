<?php

declare(strict_types=1);

namespace App\Modules\Shared\Application\Notifications;

use App\Modules\Shared\Application\DTOs\NotificationPayload;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * Base for anything that shows up in the notification centre.
 *
 * Subclasses describe the notification once, as a NotificationPayload, and
 * get the `database` channel wiring for free. Those that also e-mail add
 * 'mail' to via() and return the module's **existing** Mailable from
 * toMail() — Notification::toMail() accepts a Mailable, so converting a
 * mailed event into a notification copies no message text and leaves the
 * Mailable's own tests meaningful.
 *
 * Queued by default: toDatabase() and toMail() both render translated text
 * and may load relations, and neither belongs in the request that triggered
 * them. TenantQueue carries the account into the worker, so the RLS-guarded
 * insert still lands in the right place.
 */
abstract class InAppNotification extends Notification implements ShouldQueue
{
    use Queueable;

    abstract public function payload(object $notifiable): NotificationPayload;

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        return $this->payload($notifiable)->toArray();
    }
}
