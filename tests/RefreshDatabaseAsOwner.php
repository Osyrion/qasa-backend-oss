<?php

declare(strict_types=1);

namespace Tests;

use App\Modules\Shared\Support\OwnerConnection;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * RefreshDatabase, migrating as the schema owner.
 *
 * The default connection is the unprivileged role the application serves
 * requests as, which by design cannot issue DDL — so migrate:fresh has to go
 * through pgsql_system, exactly as it does in production. Tests still run
 * their queries on the default connection, which is the point: they exercise
 * the same privileges a real request has.
 *
 * This has to be a trait rather than a method on TestCase. A trait's methods
 * take precedence over ones inherited from a parent class, so an override on
 * TestCase would be silently ignored wherever RefreshDatabase is used.
 */
trait RefreshDatabaseAsOwner
{
    use RefreshDatabase {
        migrateFreshUsing as frameworkMigrateFreshUsing;
    }

    /**
     * @return array<string, mixed>
     */
    protected function migrateFreshUsing()
    {
        OwnerConnection::followDefaultDatabase();

        return [...$this->frameworkMigrateFreshUsing(), '--database' => OwnerConnection::NAME];
    }
}
