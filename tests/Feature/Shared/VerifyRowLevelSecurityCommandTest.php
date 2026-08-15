<?php

declare(strict_types=1);

use App\Modules\Shared\Support\OwnerConnection;
use Illuminate\Support\Facades\DB;

/**
 * qasa:rls:verify is the deploy-time half of RowLevelSecurityTest: the same
 * invariants, pointed at a real database instead of the test one. What these
 * cases have to prove is that it *discriminates* — a check that always
 * succeeds would pass the happy path just as well.
 */
it('passes on the application connection', function (): void {
    $this->artisan('qasa:rls:verify')->assertExitCode(0);
});

it('fails when pointed at the schema owner', function (): void {
    // The deployment mistake this exists for: DB_APP_USERNAME set to the
    // owning role. Postgres then ignores every policy, and nothing else in the
    // application would notice.
    $this->artisan('qasa:rls:verify', ['--connection' => OwnerConnection::NAME])
        ->expectsOutputToContain('every policy is silently inactive')
        ->assertExitCode(1);
});

it('names a tenant-owned table that has no policy', function (): void {
    // Created through the owner connection, which the test transaction does
    // not wrap — so the command, reading catalogs on its own connection, can
    // see it. Dropped in finally for the same reason.
    $owner = DB::connection(OwnerConnection::NAME);
    $owner->statement('CREATE TABLE rls_probe (id int, user_id uuid)');

    try {
        $this->artisan('qasa:rls:verify')
            ->expectsOutputToContain('rls_probe')
            ->assertExitCode(1);
    } finally {
        $owner->statement('DROP TABLE rls_probe');
    }
});

it('rejects a connection that cannot enforce policies at all', function (): void {
    config()->set('database.connections.rls_probe_sqlite', [
        'driver' => 'sqlite',
        'database' => ':memory:',
    ]);

    $this->artisan('qasa:rls:verify', ['--connection' => 'rls_probe_sqlite'])
        ->expectsOutputToContain('PostgreSQL-only')
        ->assertExitCode(1);
});
