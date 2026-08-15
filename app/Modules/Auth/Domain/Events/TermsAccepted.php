<?php

declare(strict_types=1);

namespace App\Modules\Auth\Domain\Events;

use App\Modules\Auth\Domain\Models\User;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired wherever terms_accepted_at/terms_version is stamped on a user —
 * registration, a Google-registered account, and POST /profile/accept-terms.
 * Consent without an audit trail can't be proven, which is the whole point
 * of GDPR phase 3 — see docs/plans/GDPR_COMPLIANCE_PLAN.md.
 */
class TermsAccepted
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly User $user,
        public readonly string $version,
    ) {}
}
