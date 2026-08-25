<?php

declare(strict_types=1);

namespace App\Modules\Shared\Application\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * "There is room for you now" — sent on demand to an address off the
 * waitlist, which is nobody's account yet.
 *
 * Not an `InAppNotification` for that reason: there is no notification
 * centre to write to and no preferences to respect. The locale comes from
 * the landing page the address was collected on, which is the only thing
 * known about this person.
 */
class WaitlistInvitationNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly string $registrationUrl,
        // Not `$locale`: Notification already owns a property by that name
        // (the one `->locale()` sets), and redeclaring it as readonly is a
        // fatal error rather than an override.
        private readonly string $signupLocale,
        private readonly int $expiresInDays,
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        // The recipient has no `locale` column to read — this is the one they
        // browsed in when they signed up.
        $this->locale($this->signupLocale);

        return (new MailMessage)
            ->subject(__('shared.waitlist.invitation_subject'))
            ->greeting(__('shared.waitlist.invitation_greeting'))
            ->line(__('shared.waitlist.invitation_intro'))
            ->action(__('shared.waitlist.invitation_action'), $this->registrationUrl)
            ->line(__('shared.waitlist.invitation_expiry', ['days' => $this->expiresInDays]))
            ->line(__('shared.waitlist.invitation_ignore'));
    }
}
