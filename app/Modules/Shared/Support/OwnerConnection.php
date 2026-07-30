<?php

declare(strict_types=1);

namespace App\Modules\Shared\Support;

use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Support\Facades\Event;
use Symfony\Component\Console\Input\InputInterface;

/**
 * The connection that owns the schema, and the commands that need it.
 *
 * Requests are served as an unprivileged role so that Row Level Security
 * applies to them at all (docs/plans/POSTGRES_RLS_PLAN.md). That role cannot
 * issue DDL, and cannot write a row for an account the connection is not
 * bound to — which makes `php artisan migrate` and `php artisan db:seed` fail
 * outright:
 *
 *     SQLSTATE[42501]: permission denied for schema public
 *
 * Rather than expect every deploy script, CI job and README to remember
 * `--database=pgsql_system`, the handful of commands that build or fill the
 * schema pick the owner connection themselves. An explicit --database still
 * wins, so `migrate --database=pgsql` remains a way to prove the app role
 * really is unprivileged.
 *
 * The swap is scoped to the command: CommandStarting fires before the input
 * has been bound to the command's definition, so the option cannot be set on
 * it — the default connection is changed instead and put back when the
 * command finishes. Commands invoked from inside another command do not
 * re-fire the event, so a nested `migrate` inherits the elevated default from
 * the `migrate:fresh` that called it.
 */
final class OwnerConnection
{
    public const NAME = 'pgsql_system';

    /**
     * Commands that build or fill the schema.
     *
     * @var list<string>
     */
    private const SCHEMA_COMMANDS = [
        'migrate',
        'migrate:fresh',
        'migrate:install',
        'migrate:refresh',
        'migrate:reset',
        'migrate:rollback',
        'migrate:status',
        'db:seed',
        'db:wipe',
        'schema:dump',
    ];

    /**
     * Commands currently running with the default swapped, innermost last.
     *
     * @var list<string>
     */
    private static array $elevated = [];

    private static ?string $previousDefault = null;

    /**
     * Point the owner connection at whatever database the default one uses.
     *
     * `artisan test --parallel` gives each worker its own database by
     * rewriting the default connection's config. It has no idea a second
     * connection exists, so without this the owner would keep migrating — and
     * the tests reaching for it keep reading — the shared database instead.
     */
    public static function followDefaultDatabase(): void
    {
        config([
            'database.connections.'.self::NAME.'.database' => config('database.connections.pgsql.database'),
        ]);
    }

    public static function routeSchemaCommands(): void
    {
        Event::listen(function (CommandStarting $event): void {
            if (! self::wantsOwner($event->command, $event->input)) {
                return;
            }

            if (self::$elevated === []) {
                self::$previousDefault = (string) config('database.default');
                config(['database.default' => self::NAME]);
            }

            self::$elevated[] = (string) $event->command;
        });

        Event::listen(function (CommandFinished $event): void {
            if (self::$elevated === [] || end(self::$elevated) !== $event->command) {
                return;
            }

            array_pop(self::$elevated);

            if (self::$elevated === [] && self::$previousDefault !== null) {
                config(['database.default' => self::$previousDefault]);
                self::$previousDefault = null;
            }
        });
    }

    private static function wantsOwner(?string $command, InputInterface $input): bool
    {
        if ($command === null || ! in_array($command, self::SCHEMA_COMMANDS, true)) {
            return false;
        }

        // Only meaningful for the Postgres role split. A deployment pointing
        // both connections at the same account gets the same behaviour it had
        // before — and no tenant isolation, which stubs/oss/README.md warns
        // about.
        if (config('database.default') !== 'pgsql' || config('database.connections.'.self::NAME) === null) {
            return false;
        }

        return ! $input->hasParameterOption('--database');
    }
}
