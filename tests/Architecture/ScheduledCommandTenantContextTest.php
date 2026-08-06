<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Tests\RefreshDatabaseAsOwner;
use Tests\TestCase;

/**
 * `ENGINEERING_GUARDRAILS_PLAN.md` part A5: every console command that
 * writes to a row-level-security-protected table must bind a tenant via
 * `TenantContext::set()/for()/forEachAccount()` — a console command starts
 * bound to no account, so an unbound write against an RLS-protected table
 * is either silently refused (nothing to see, nothing to update) or
 * rejected outright (`SQLSTATE 42501`) at runtime. This moves that failure
 * mode to CI, the same way TenantScopeTest moved "forgot HasUserScope" from
 * a production leak to a build failure.
 *
 * Queue Jobs are deliberately out of scope here, unlike the plan's literal
 * "command / job" wording: `TenantQueue::listen()` (registered globally)
 * captures whatever account was bound at dispatch time into the job's queue
 * payload and rebinds it automatically before every `ShouldQueue` job's
 * `handle()` runs. A job doesn't need its own `TenantContext::` call any
 * more than a controller action does — both inherit a binding somebody else
 * already established. Checked directly: RunGoogleCalendarSyncJob,
 * DeliverWebhookJob and ProcessInboxItemJob all write to RLS-protected
 * tables (google_calendar_sync_runs, webhook_deliveries, invoice_inbox_items)
 * with zero TenantContext:: calls, and all three are correct precisely
 * because they implement ShouldQueue.
 *
 * Every command audited while building this already does the right thing —
 * this is a pure regression guard, not a fix-up. One near-miss worth noting:
 * `ExpireSubscriptionOrdersCommand` writes to `subscription_orders` with no
 * TenantContext call and no violation — that table carries no RLS policy at
 * all (billing tables are scoped by explicit ->forUser()/->where() instead,
 * see TenantScopeTest's allowlist for Subscription/SubscriptionOrder), so
 * there is no RLS failure mode to guard against there.
 */
uses(TestCase::class, RefreshDatabaseAsOwner::class);

/**
 * @return list<string>
 */
function rlsProtectedTables(): array
{
    /** @var list<object{tablename: string}> $rows */
    $rows = DB::select('SELECT DISTINCT tablename FROM pg_policies WHERE schemaname = ?', ['public']);

    return array_map(static fn (object $row): string => $row->tablename, $rows);
}

/**
 * @return array<string, class-string<Model>> table name => Domain model class
 */
function tableToModelMap(): array
{
    $modulesPath = dirname(__DIR__, 2).'/app/Modules';
    $map = [];

    foreach (glob($modulesPath.'/*/Domain/Models/*.php') ?: [] as $path) {
        $relative = str_replace([$modulesPath.'/', '.php'], '', $path);
        $class = 'App\\Modules\\'.str_replace('/', '\\', $relative);

        if (! is_subclass_of($class, Model::class)) {
            continue;
        }

        /** @var Model $model */
        $model = new $class;
        $map[$model->getTable()] = $class;
    }

    return $map;
}

/**
 * @param  list<string>  $shortNames
 */
function fileReferencesAnyModel(string $content, array $shortNames): bool
{
    foreach ($shortNames as $shortName) {
        if (preg_match('/\b'.preg_quote($shortName, '/').'\b/', $content) === 1) {
            return true;
        }
    }

    return false;
}

it('binds a tenant via TenantContext before a console command writes to an RLS-protected table', function (): void {
    $protectedModels = tableToModelMap();
    $protectedTables = array_intersect_key($protectedModels, array_flip(rlsProtectedTables()));

    /** @var list<string> $shortNames */
    $shortNames = array_values(array_unique(array_map(
        static fn (string $class): string => (string) class_basename($class),
        $protectedTables,
    )));

    $writePattern = '/(::create\(|->create\(|->forceCreate\(|->updateOrCreate\(|->firstOrCreate\(|->save\(|::update\(|->update\(|::delete\(|->delete\(|->forceDelete\(|forceFill\(|->increment\(|->decrement\()/';

    $violations = [];

    foreach (glob(dirname(__DIR__, 2).'/app/Modules/*/Presentation/Console/*.php') ?: [] as $path) {
        $content = (string) file_get_contents($path);
        $relativePath = 'app/'.ltrim(str_replace(dirname(__DIR__, 2).'/app', '', $path), '/');

        if (! str_contains($content, 'extends Command')) {
            continue;
        }

        if (str_contains($content, 'TenantContext::')) {
            continue;
        }

        if (! fileReferencesAnyModel($content, $shortNames)) {
            continue;
        }

        if (preg_match($writePattern, $content) !== 1) {
            continue;
        }

        $violations[] = $relativePath;
    }

    expect($violations)->toBe(
        [],
        "These console commands write to an RLS-protected model with no TenantContext:: binding anywhere in the file:\n".implode("\n", $violations),
    );
});

it('has at least one RLS-protected table to check against (the check is not accidentally a no-op)', function (): void {
    expect(rlsProtectedTables())->not->toBe([]);
});
