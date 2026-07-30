<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Auth\Domain\Models\User;
use App\Modules\Shared\Support\TenantContext;

/**
 * The account a tenant-owned factory belongs to when the caller does not say.
 *
 * `User::factory()` on its own creates a *new* account and leaves the
 * connection bound to whatever it was, so under the tenant policies the row
 * is refused before the test reaches its first assertion. Worse when it does
 * not fail: a fixture quietly built for an account nobody is looking at was
 * how `Invoice::factory()->create(['user_id' => $u])` ended up pointing at
 * another account's client, long before any of this existed.
 *
 * So: use the bound account if there is one, and otherwise create an account
 * and bind to it. Binding is not a flourish here — it is the precondition for
 * the row to be insertable at all, and it makes the plain reading of
 * `WebhookEndpoint::factory()->create()` — "an endpoint belonging to whoever
 * we are" — true.
 *
 * An explicit user_id still wins, and a test that deliberately sets up a
 * second account says so with asAccount().
 */
final class BoundAccount
{
    public static function id(): string
    {
        $bound = TenantContext::current();

        if ($bound !== null) {
            return $bound;
        }

        /** @var class-string<User> $model */
        $model = config('auth.providers.users.model', User::class);

        // users is tenant-scoped too (phase 7, docs/plans/POSTGRES_RLS_PLAN.md)
        // — the row about to be created is its own account, so the id has to
        // be bound before the insert, not after: HasUuids only fills the key
        // in if it's still empty, so passing it explicitly here is a no-op
        // for the trait and exactly what WITH CHECK needs.
        $id = (string) (new $model)->newUniqueId();
        TenantContext::set($id);

        $model::factory()->create(['id' => $id]);

        return $id;
    }
}
