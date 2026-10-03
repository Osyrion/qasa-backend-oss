<?php

declare(strict_types=1);

namespace App\Modules\Shared\Support;

use Illuminate\Support\Facades\DB;

/**
 * The account the current database connection is acting for.
 *
 * Carried as a Postgres session variable so that Row Level Security policies
 * can read it (docs/plans/POSTGRES_RLS_PLAN.md). No policy consults it yet —
 * this establishes and, more importantly, *proves* the lifecycle first.
 *
 * The dangerous failure is a stale value: a long-lived queue worker keeps its
 * connection between jobs, so a value left behind by one account's job would
 * be inherited by the next. Everything here is therefore built around
 * clearing rather than setting — the variable is wiped at the start of every
 * request and around every job, and only set once an account is actually
 * known.
 *
 * SET rather than SET LOCAL: requests are not wrapped in a transaction, and
 * wrapping them would change error semantics and hold locks for the length of
 * a request. That trade only holds while connections are not shared mid-flight
 * — a transaction-mode pooler like pgbouncer would break it, and the plan
 * records that.
 */
final class TenantContext
{
    public const SETTING = 'app.account_owner_id';

    /**
     * How many account ids forEachAccount() holds at once.
     *
     * Big enough that the paging is not the cost — a job does far more per
     * account than one row of bookkeeping — and small enough that the array
     * never grows with the customer base.
     */
    private const ACCOUNT_PAGE = 1000;

    /**
     * Bindings parked by push(), innermost last.
     *
     * @var list<string|null>
     */
    private static array $suspended = [];

    /**
     * The last value written, so a reconnected connection can be given it
     * back. Not a cache for current(): that always asks the connection, which
     * is the only thing a policy actually reads.
     */
    private static ?string $bound = null;

    /**
     * Bind the connection to an account. Takes the account *owner* id, since
     * team members share their owner's data.
     */
    public static function set(string $accountOwnerId): void
    {
        self::write($accountOwnerId);
    }

    /**
     * Detach the connection from any account. An unset variable reads as NULL
     * in SQL, which is what makes a policy comparing against it match nothing.
     */
    public static function clear(): void
    {
        self::write('');
    }

    /**
     * What the connection is currently bound to, or null when unbound.
     */
    public static function current(): ?string
    {
        /** @var array<int, object{value: string|null}> $rows */
        $rows = DB::select('SELECT current_setting(?, true) AS value', [self::SETTING]);
        $value = $rows[0]->value ?? null;

        return ($value === null || $value === '') ? null : $value;
    }

    /**
     * Remember the current binding at the edge of a job.
     *
     * Deliberately does not clear. On a worker there is nothing bound between
     * jobs, so this parks null and pop() restores null — a job that forgets to
     * bind still sees nothing rather than whoever ran before it, which is the
     * isolation that matters.
     *
     * Clearing here would break the other case. Under the sync driver, and for
     * dispatchSync, a job runs inside the request that dispatched it, and a
     * queued listener carrying a model re-reads that model from the database
     * as it deserialises. Unbound, that read finds nothing and the job dies on
     * a model it was just handed.
     */
    public static function push(): void
    {
        self::$suspended[] = self::current();
    }

    /**
     * Put back whatever push() parked.
     */
    public static function pop(): void
    {
        $previous = array_pop(self::$suspended);

        $previous === null ? self::clear() : self::set($previous);
    }

    /**
     * Run $work bound to an account, restoring whatever was bound before.
     *
     * For the paths that legitimately span accounts — a scheduled command
     * walking every opted-in account, say — which need to visit each in turn
     * rather than see them all at once.
     *
     * @template TReturn
     *
     * @param  callable(): TReturn  $work
     * @return TReturn
     */
    public static function for(string $accountOwnerId, callable $work): mixed
    {
        self::push();
        self::set($accountOwnerId);

        try {
            return $work();
        } finally {
            self::pop();
        }
    }

    /**
     * Run $work once for every account, bound to each in turn.
     *
     * Maintenance that spans accounts — retention purges, backfills — used to
     * be a single statement across the whole table. Under a policy that
     * statement only ever reaches the bound account, so it silently does
     * nothing at all when nothing is bound. Visiting the accounts one at a
     * time is the honest replacement.
     *
     * users carries a policy now like everything else, which is exactly what
     * makes a direct Eloquent scan of it unusable here — nothing is bound
     * yet, that's the whole point of this method. tenant_account_ids_after()
     * is the narrow SECURITY DEFINER function that walks it instead (see the
     * phase 7 migrations, docs/plans/POSTGRES_RLS_PLAN.md), already deduped
     * to one row per account and already including soft-deleted owners —
     * their rows are still in the tables being purged.
     *
     * A page at a time, by keyset rather than offset. The first version read
     * every account id into one array, which is a sequential scan of `users`
     * plus a sort and an array that grows with the customer base for as long
     * as the job runs. Resuming after the last id keeps memory flat, and —
     * with the expression index on coalesce(owner_id, id) — makes each page an
     * index range scan, so the whole walk is still one pass rather than one
     * scan per page. Ordering by the account id is also what makes the walk
     * safe against an account being created while it runs: a new id is either
     * ahead of the cursor and gets visited, or behind it and was already
     * accounted for. It can never displace another.
     *
     * What this does not change is the shape of the cost: one turn per
     * account is inherent to a policy that only ever admits one. A job that
     * runs a query per account runs N queries, and no paging here alters
     * that.
     *
     * @param  callable(string): void  $work
     */
    public static function forEachAccount(callable $work): void
    {
        $after = null;

        while (true) {
            /** @var list<object{account: string}> $rows */
            $rows = DB::select(
                'SELECT public.tenant_account_ids_after(?, ?) AS account',
                [$after, self::ACCOUNT_PAGE],
            );

            if ($rows === []) {
                return;
            }

            foreach ($rows as $row) {
                self::for($row->account, fn () => $work($row->account));
            }

            $after = $rows[array_key_last($rows)]->account;
        }
    }

    /**
     * Re-apply the binding to a connection that has just been re-established.
     *
     * The binding is session state, so a dropped connection loses it and the
     * replacement comes back unbound. That is fail-closed rather than a leak
     * — queries return nothing — but "nothing" is a silent wrong answer, and
     * a long-running command holding a connection across an outage is exactly
     * where it would happen.
     */
    public static function restoreAfterReconnect(): void
    {
        if (self::$bound !== null) {
            self::write(self::$bound);
        }
    }

    private static function write(string $value): void
    {
        // set_config, not SET: the value is a bound parameter, so it cannot
        // be spliced into the statement. `false` keeps it for the session
        // rather than the transaction.
        DB::statement('SELECT set_config(?, ?, false)', [self::SETTING, $value]);

        self::$bound = $value === '' ? null : $value;
    }
}
