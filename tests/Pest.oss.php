<?php

declare(strict_types=1);

use App\Modules\Auth\Domain\Models\User;

/*
 * Core stand-ins for the helpers tests/Pest.edition.php provides in the SaaS
 * repository. Required only when that overlay is absent, so exactly one
 * definition of each exists at a time — which also keeps PHPStan from
 * resolving calls to the wrong one.
 */

/**
 * @param  array<string, mixed>  $attributes
 */
function createSaasUser(array $attributes = []): User
{
    return createUser($attributes);
}

function subscribeToPaidPlan(User $owner): void
{
    // No plans in this edition, so there is nothing to unlock.
}
