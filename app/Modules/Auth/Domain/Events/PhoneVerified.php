<?php

declare(strict_types=1);

namespace App\Modules\Auth\Domain\Events;

use App\Modules\Auth\Domain\Models\User;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * The account owner proved they hold the phone number on their profile.
 *
 * Core fires it, premium acts on it: the SaaS module converts a pending
 * trial entitlement into a real trial here. That indirection is the whole
 * point — Auth must not know trials exist, and the OSS edition has no
 * listener for this at all.
 */
class PhoneVerified
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly User $user,
    ) {}
}
