<?php

declare(strict_types=1);

namespace App\Modules\Auth\Domain\Events;

use App\Modules\Auth\Domain\Models\User;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Only dispatched when the email resolved to a real user (wrong password, or
 * a password attempt on a Google-only account) — an unknown email has no
 * tenant to attach an activity_log row to, and is logged separately via the
 * 'security' channel instead (see LoginAction) to avoid turning this into an
 * email-enumeration oracle.
 */
class LoginFailed
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly User $user,
        public readonly string $reason,
    ) {}
}
