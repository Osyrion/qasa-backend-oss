<?php

declare(strict_types=1);

namespace App\Modules\Auth\Domain\Events;

use App\Modules\Auth\Domain\Models\User;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired when a 2FA secret is generated (EnableTwoFactorAction), not when it
 * becomes active — see TwoFactorConfirmed for that.
 */
class TwoFactorEnabled
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly User $user,
    ) {}
}
