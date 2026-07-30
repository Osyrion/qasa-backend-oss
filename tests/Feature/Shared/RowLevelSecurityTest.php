<?php

declare(strict_types=1);

use App\Modules\Clients\Domain\Models\Client;
use App\Modules\Invoicing\Domain\Models\Invoice;
use App\Modules\Invoicing\Domain\Models\InvoiceItem;
use App\Modules\Shared\Support\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * What the policies are actually for.
 *
 * The rest of the suite proves the application still works with Row Level
 * Security on. These prove the thing it was turned on for: that isolation no
 * longer depends on remembering to scope a query, and holds for raw SQL that
 * never sees an Eloquent scope at all.
 */
it('hides another account rows from a raw query', function (): void {
    $owner = createUser();
    $ownClient = Client::factory()->create(['user_id' => $owner->id]);

    $stranger = createUser();
    $strangerClient = asAccount($stranger, fn () => Client::factory()->create(['user_id' => $stranger->id]));

    // DB::table bypasses the model entirely, so no global scope applies —
    // this is exactly the path the Eloquent scope never covered.
    $visible = asAccount($owner, fn () => DB::table('clients')->pluck('id')->all());

    expect($visible)->toContain($ownClient->id)
        ->and($visible)->not->toContain($strangerClient->id);
});

it('hides line items of another account, which carry no user_id of their own', function (): void {
    $owner = createUser();
    $client = Client::factory()->create(['user_id' => $owner->id]);
    $invoice = Invoice::factory()->create(['user_id' => $owner->id, 'client_id' => $client->id]);
    $item = InvoiceItem::factory()->create(['invoice_id' => $invoice->id]);

    $stranger = createUser();

    // invoice_items has no user_id, so nothing scoped it before — not even
    // the Eloquent global scope, which needs a column to filter on. The
    // policy reaches the account through the invoice, and the invoice's own
    // policy is what makes that reach come up empty.
    expect(asAccount($stranger, fn () => DB::table('invoice_items')->where('id', $item->id)->exists()))
        ->toBeFalse()
        ->and(asAccount($owner, fn () => DB::table('invoice_items')->where('id', $item->id)->exists()))
        ->toBeTrue();
});

it('refuses to hang a line item off another account parent', function (): void {
    $owner = createUser();
    $client = Client::factory()->create(['user_id' => $owner->id]);
    $invoice = Invoice::factory()->create(['user_id' => $owner->id, 'client_id' => $client->id]);

    $stranger = createUser();

    // WITH CHECK on the parent lookup: without it a row could be attached to
    // someone else's invoice and simply be unreadable afterwards.
    expect(fn () => asAccount($stranger, fn () => InvoiceItem::factory()->create(['invoice_id' => $invoice->id])))
        ->toThrow(QueryException::class);
});

it('refuses to write a row into another account', function (): void {
    $owner = createUser();
    $stranger = createUser();

    // WITH CHECK, not just USING: without it a row could be inserted into
    // someone else's account and merely be unreadable afterwards.
    expect(fn () => asAccount($stranger, fn () => Client::factory()->create(['user_id' => $owner->id])))
        ->toThrow(QueryException::class);
});

it('hides another account\'s own row from a raw query', function (): void {
    $owner = createUser();
    $stranger = createUser();

    // users carries a policy now too (phase 7) — the same fail-closed rule
    // as every other table, just with the account being the row's own id
    // (coalesce(owner_id, id) in the SaaS edition) rather than a foreign
    // user_id column.
    $visible = asAccount($owner, fn () => DB::table('users')->pluck('id')->all());

    expect($visible)->toContain($owner->id)
        ->and($visible)->not->toContain($stranger->id);
});

it('refuses to write a users row for another account', function (): void {
    $model = userModel();

    // WITH CHECK on users itself: a fresh row's own id can never equal
    // some other account's id, so binding to one account and inserting a
    // new row is refused outright — exactly the WITH CHECK a member's
    // owner_id would also need, just without depending on the SaaS-only
    // column to say so.
    expect(fn () => asAccount(createUser(), fn () => $model::create([
        'name' => 'Someone',
        'surname' => 'Else',
        'email' => fake()->unique()->safeEmail(),
        'password' => 'irrelevant',
        'default_currency' => 'EUR',
        'invoice_prefix' => 'FA',
        'locale' => 'sk',
        'is_vat_payer' => false,
        'tax_flat_rate' => 0,
    ])))->toThrow(QueryException::class);
});

it('hides another account\'s tokens from a raw query', function (): void {
    $owner = createUser();
    $ownToken = $owner->createToken('own-token');

    $stranger = createUser();

    $visible = asAccount($owner, fn () => DB::table('personal_access_tokens')->pluck('id')->all());

    expect($visible)->toContain($ownToken->accessToken->id);

    asAccount($stranger, function () use ($ownToken): void {
        expect(DB::table('personal_access_tokens')->where('id', $ownToken->accessToken->id)->exists())->toBeFalse();
    });
});

it('hides another account\'s role assignment from a raw query', function (): void {
    $owner = createSaasUser();
    $stranger = createSaasUser();

    $visible = asAccount($owner, fn () => DB::table('model_has_roles')->where('model_uuid', $owner->id)->exists());
    $hidden = asAccount($stranger, fn () => DB::table('model_has_roles')->where('model_uuid', $owner->id)->exists());

    expect($visible)->toBeTrue()->and($hidden)->toBeFalse();
})->skip(fn (): bool => config('qasa.edition') === 'oss', 'roles are a SaaS concept');

it('shows nothing at all when no account is bound', function (): void {
    $owner = createUser();
    Client::factory()->create(['user_id' => $owner->id]);

    TenantContext::clear();

    // Fail-closed: an unset session variable reads as NULL, and the policy's
    // comparison against NULL matches no row. A connection that nobody has
    // bound sees an empty database rather than all of it.
    expect(DB::table('clients')->count())->toBe(0)
        ->and(DB::table('invoices')->count())->toBe(0)
        ->and(DB::table('users')->count())->toBe(0);
});

it('runs as a role that policies actually apply to', function (): void {
    /** @var object{is_superuser: bool, bypasses_rls: bool, owns_tables: int} $role */
    $role = DB::selectOne("
        SELECT r.rolsuper AS is_superuser,
               r.rolbypassrls AS bypasses_rls,
               (SELECT count(*) FROM pg_tables WHERE schemaname = 'public' AND tableowner = current_user) AS owns_tables
        FROM pg_roles r
        WHERE r.rolname = current_user
    ");

    // Any one of these silently switches every policy off, and nothing else
    // in the suite would notice — a deployment pointing the app back at the
    // owner would look perfectly healthy.
    expect($role->is_superuser)->toBeFalse()
        ->and($role->bypasses_rls)->toBeFalse()
        ->and($role->owns_tables)->toBe(0);
});

it('guards the tables the policies were written for', function (): void {
    // Every table that must be protected *where it exists*. Filtering by
    // existence is what makes one list work for both editions: the generated
    // core has no premium tables, so they drop out — but a premium table that
    // is present and unprotected stays in and fails, which is the direction
    // that matters.
    $expected = collect([
        // own their account through user_id, or — users — their own id
        'activity_log', 'ai_credentials', 'bank_accounts', 'clients',
        'contribution_payments', 'events', 'expenses',
        'google_calendar_connections', 'google_calendar_sync_runs',
        'idempotency_keys', 'invoice_inbox_items', 'invoices', 'orders',
        'payment_orders', 'price_lists', 'quotes', 'rates',
        'recurring_invoice_templates', 'stripe_connect_accounts',
        'supplier_invoices', 'time_entries', 'trips', 'users', 'vat_rates',
        'vehicles', 'webhook_endpoints',

        // own their account through owner_id
        'team_invitations',

        // reach it through a parent
        'contact_persons', 'google_calendar_event_links', 'invoice_items',
        'invoice_payments', 'invoice_work_report_lines', 'model_has_permissions',
        'model_has_roles', 'order_attachments', 'order_items', 'order_notes',
        'payment_order_items', 'personal_access_tokens', 'price_list_items',
        'quote_items', 'recurring_invoice_template_items',
        'supplier_invoice_vat_lines', 'webhook_deliveries',

        // shared reference rows plus per-account overrides
        'exchange_rates',
    ])->filter(fn (string $table): bool => DB::getSchemaBuilder()->hasTable($table))->values()->all();

    $protected = DB::table('pg_class as c')
        ->join('pg_namespace as n', 'n.oid', '=', 'c.relnamespace')
        ->where('n.nspname', 'public')
        ->where('c.relkind', 'r')
        ->where('c.relrowsecurity', true)
        ->pluck('c.relname')
        ->all();

    expect($protected)->toEqualCanonicalizing($expected);
});

it('leaves no tenant-owned table unprotected', function (): void {
    // The invariant the table list above cannot express: a table that names
    // an owning account must not be able to appear without a policy. This is
    // the check that was missing when phase 4 was left half-done — sixteen
    // tables carried user_id and nothing enforced anything on any of them.
    //
    // Both column names, because both are used: user_id is the usual one,
    // owner_id is what team_invitations calls it — and looking only for
    // user_id is exactly how that one went unnoticed.
    $allowed = [
        // Billing, read across accounts by the Admin module on purpose. Not
        // auto-scoped in the application either — see the tenant scope
        // allowlist in tests/Architecture/TenantScopeTest.php.
        'subscriptions' => 'billing, read across accounts by Admin',
        'subscription_invoices' => 'billing, read across accounts by Admin',
        'subscription_orders' => 'billing, read across accounts by Admin',
        'subscription_usages' => 'usage metering, aggregated across accounts by Admin',

        // Laravel's own session table, keyed by user_id but managed by the
        // framework and never read as tenant data.
        'sessions' => 'framework-managed',
    ];

    $unprotected = DB::table('pg_class as c')
        ->join('pg_namespace as n', 'n.oid', '=', 'c.relnamespace')
        ->join('pg_attribute as a', function ($join): void {
            $join->on('a.attrelid', '=', 'c.oid')->whereIn('a.attname', ['user_id', 'owner_id']);
        })
        ->where('n.nspname', 'public')
        ->where('c.relkind', 'r')
        ->where('c.relrowsecurity', false)
        ->where('a.attnum', '>', 0)
        ->where('a.attisdropped', false)
        ->pluck('c.relname')
        ->reject(fn (string $table): bool => array_key_exists($table, $allowed))
        ->unique()
        ->values()
        ->all();

    expect($unprotected)->toBe([]);
});
