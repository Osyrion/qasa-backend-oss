<?php

declare(strict_types=1);

namespace App\Modules\Auth\Application\Actions;

use App\Modules\Auth\Domain\Events\TermsAccepted;
use App\Modules\Auth\Domain\Models\User;

/**
 * Covers two cases with one call: an account that predates terms_accepted_at
 * (null → set), and one re-accepting after config('gdpr.terms_version')
 * moved. Both just stamp "now, current version" — there is nothing to branch
 * on.
 */
class AcceptTermsAction
{
    public function execute(User $user): User
    {
        $version = (string) config('gdpr.terms_version');

        $user->update([
            'terms_accepted_at' => now(),
            'terms_version' => $version,
        ]);

        event(new TermsAccepted($user, $version));

        return $user;
    }
}
