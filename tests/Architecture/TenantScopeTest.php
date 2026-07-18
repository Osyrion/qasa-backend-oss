<?php

declare(strict_types=1);

use App\Modules\Invoicing\Domain\Models\ExchangeRate;
use App\Modules\Orders\Domain\Models\OrderAttachment;
use App\Modules\Orders\Domain\Models\OrderNote;
use App\Modules\Shared\Domain\Models\IdempotencyKey;
use App\Modules\Shared\Traits\HasUserScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Every Domain model whose table has a `user_id` column must use
 * HasUserScope, or a developer forgetting the trait on a new tenant model
 * becomes a silent cross-tenant data leak. Needs a real migrated DB
 * (Schema::hasColumn), unlike the purely static checks in
 * ModuleBoundariesTest, so this file opts into TestCase + RefreshDatabase
 * itself rather than relying on Pest.php's blanket Feature/Unit `uses()`.
 */
uses(TestCase::class, RefreshDatabase::class);

/**
 * Models with a `user_id` column that intentionally do not use
 * HasUserScope, and why. The test fails if one of these turns out to
 * already use the trait, so an entry can't outlive its justification.
 *
 * @return array<class-string<Model>, string>
 */
function tenantScopeAllowlist(): array
{
    return [
        // user_id here is "who uploaded / who wrote this", not the tenant
        // column — access is scoped through the parent Order (which itself
        // uses HasUserScope) and its policy, never queried standalone.
        OrderAttachment::class => 'scoped via parent Order, user_id is an audit column',
        OrderNote::class => 'scoped via parent Order, user_id is an audit column',

        // The lookup key is (Idempotency-Key header, user_id, route) hashed
        // together by Shared\Presentation\Middleware\IdempotencyKey — it is
        // never listed/queried the way tenant-scoped resources are.
        IdempotencyKey::class => 'scope enforced by IdempotencyKey middleware, not by listing queries',

        // user_id is nullable and mixes global system rates (user_id = null)
        // with per-account overrides in the same table — HasUserScope's
        // global scope would hide the system rows from every authenticated
        // request instead of just filtering overrides.
        ExchangeRate::class => 'nullable user_id mixes shared system rows with per-account overrides',
    ];
}

it('applies HasUserScope to every Domain model with a user_id column', function (): void {
    $modulesPath = dirname(__DIR__, 2).'/app/Modules';

    $classes = collect(glob($modulesPath.'/*/Domain/Models/*.php') ?: [])
        ->map(function (string $path) use ($modulesPath): string {
            $relative = str_replace([$modulesPath.'/', '.php'], '', $path);

            return 'App\\Modules\\'.str_replace('/', '\\', $relative);
        })
        ->filter(fn (string $class): bool => is_subclass_of($class, Model::class));

    $allowlist = tenantScopeAllowlist();
    $missing = [];
    $staleAllowlist = [];

    foreach ($classes as $class) {
        /** @var Model $model */
        $model = new $class;
        $table = $model->getTable();

        if (! Schema::hasColumn($table, 'user_id')) {
            continue;
        }

        $usesTrait = in_array(HasUserScope::class, class_uses_recursive($class), true);
        $isAllowlisted = array_key_exists($class, $allowlist);

        if ($usesTrait && $isAllowlisted) {
            $staleAllowlist[] = $class;
        }

        if (! $usesTrait && ! $isAllowlisted) {
            $missing[] = $class;
        }
    }

    expect($missing)->toBe(
        [],
        "These models have a user_id column but don't use HasUserScope and aren't allowlisted: ".implode(', ', $missing),
    );

    expect($staleAllowlist)->toBe(
        [],
        'These models now use HasUserScope — remove them from the allowlist: '.implode(', ', $staleAllowlist),
    );
});

it('does not allowlist a model that does not even have a user_id column', function (): void {
    $modulesPath = dirname(__DIR__, 2).'/app/Modules';

    $classes = collect(glob($modulesPath.'/*/Domain/Models/*.php') ?: [])
        ->map(function (string $path) use ($modulesPath): string {
            $relative = str_replace([$modulesPath.'/', '.php'], '', $path);

            return 'App\\Modules\\'.str_replace('/', '\\', $relative);
        })
        ->filter(fn (string $class): bool => is_subclass_of($class, Model::class));

    $stale = [];

    foreach (array_keys(tenantScopeAllowlist()) as $allowlistedClass) {
        if (! $classes->contains($allowlistedClass)) {
            $stale[] = $allowlistedClass;

            continue;
        }

        /** @var Model $model */
        $model = new $allowlistedClass;

        if (! Schema::hasColumn($model->getTable(), 'user_id')) {
            $stale[] = $allowlistedClass;
        }
    }

    expect($stale)->toBe([], 'Stale allowlist entries (class gone or no longer has user_id): '.implode(', ', $stale));
});
