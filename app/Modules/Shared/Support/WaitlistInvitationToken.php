<?php

declare(strict_types=1);

namespace App\Modules\Shared\Support;

use Illuminate\Support\Str;

/**
 * The one place that knows how a waitlist invitation token is made and
 * matched.
 *
 * Two sides need to agree: the action that issues one and stores the hash,
 * and registration, which is handed the raw token back and has to find the
 * row. Splitting that across both would be two definitions of the same
 * secret, and the kind that fails silently — a mismatch does not error, it
 * just never finds anybody.
 */
final class WaitlistInvitationToken
{
    /** Long enough that guessing is not a strategy; the hash column is sized for sha256 hex. */
    private const LENGTH = 64;

    public static function generate(): string
    {
        return Str::random(self::LENGTH);
    }

    /**
     * Unsalted sha256 on purpose. A salted, per-row hash (bcrypt and friends)
     * cannot be looked up by equality, and this value is a 64-character
     * random string rather than a human-chosen password — there is no
     * dictionary to attack and nothing for a rainbow table to precompute.
     */
    public static function hash(string $raw): string
    {
        return hash('sha256', $raw);
    }
}
