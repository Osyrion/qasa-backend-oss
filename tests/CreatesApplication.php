<?php

declare(strict_types=1);

namespace Tests;

use App\Modules\Shared\Support\OwnerConnection;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\ParallelTesting;
use Illuminate\Support\Facades\Schema;

/**
 * How `artisan test --parallel` builds its application.
 *
 * The parallel runner looks for this trait before falling back to plain
 * bootstrap/app.php, and it is the only hook that runs once per worker
 * *before* any test case — which is where the per-worker database has to be
 * created.
 *
 * The framework creates it on the default connection, and the default
 * connection is now the unprivileged role requests are served as:
 *
 *     SQLSTATE[42501]: permission denied to create database
 *
 * That took the whole suite down under --parallel. Creating them here, as the
 * owner, leaves the framework's own check finding them already present.
 *
 * Nothing in the suite `use`s this trait — the parallel runner composes it
 * onto an anonymous class of its own, by name, which static analysis has no
 * way to see.
 *
 * @phpstan-ignore trait.unused
 */
trait CreatesApplication
{
    public function createApplication(): Application
    {
        /** @var Application $app */
        $app = require __DIR__.'/../bootstrap/app.php';

        $app->make(Kernel::class)->bootstrap();

        ParallelTesting::setUpProcess(function (int $token): void {
            $database = config('database.connections.pgsql.database').'_test_'.$token;

            $exists = DB::connection(OwnerConnection::NAME)
                ->select('SELECT 1 FROM pg_database WHERE datname = ?', [$database]);

            if ($exists === []) {
                Schema::connection(OwnerConnection::NAME)->createDatabase($database);
            }
        });

        return $app;
    }
}
