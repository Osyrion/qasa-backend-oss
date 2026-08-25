<?php

declare(strict_types=1);

/**
 * Module isolation: what one module may know about another.
 *
 * Every module gets two layers — its **public API**, and everything else:
 *
 *   public    Application\Contracts, Application\DTOs, Domain\Contracts,
 *             Domain\Events, Domain\Enums, Domain\ValueObjects
 *   internal  everything else — and that deliberately includes Domain\Models,
 *             Domain\Services, Domain\Rules and the repositories, alongside
 *             Application's Services, Actions, Jobs and Listeners, the whole of
 *             Infrastructure and Presentation, migrations and seeders
 *
 * What goes out is a **contract or a value**: an interface, a DTO, an event, an
 * enum, a value object. What stays in is the **model**: aggregates, entities,
 * repositories, and the services that operate on them.
 *
 * That line is the whole point of a modular monolith. A module that hands out
 * its entities has not published an API, it has published its database — the
 * consumer type-hints the aggregate, walks its relations, and from then on the
 * owning module cannot rename a column, split a table or change a relation
 * without breaking code it does not own. Two modules that share a contract can
 * be pulled apart; two modules that share a model cannot.
 *
 * There is no allowlist to add a class to. The way to make something public is
 * to **move it** into one of the directories above — a value object into
 * `Domain/ValueObjects`, an identifier into the same place, a capability behind
 * an interface in `Application/Contracts`. The directory is the declaration of
 * intent, so a value object still sitting in `Domain/Banking` or
 * `Domain/Support` shows up as a violation, and that is the correct signal: it
 * is telling you where the class belongs.
 *
 * One class is a **named exception rather than a directory**: the account
 * model. It is the tenant root, not a module's aggregate, and Team may reach
 * it because team membership *is* rows in that table. The argument is written
 * out in full at $accountModel below, and
 * tests/Architecture/DeptracTest.php pins the list of modules allowed near it —
 * widening that list fails the build, deliberately.
 *
 * A module may use any other module's public API and nothing else. Reaching
 * into another module's internals couples us to implementation details that
 * are free to change inside the owning module, and it is exactly what the
 * contract-plus-binding pattern described in CLAUDE.md exists to avoid: the
 * consumer depends on `Application\Contracts\FooInterface`, the owner binds a
 * concrete Service to it in its own ServiceProvider.
 *
 * `Shared` is the shared kernel and is public in full — that is what it is for.
 *
 * The module list is read off the filesystem rather than hardcoded, so a new
 * module is covered the moment it exists, and the OSS build (which deletes the
 * premium modules outright) gets a config describing the tree it actually has.
 *
 * Direction *within* a module — Domain not knowing Infrastructure — is a
 * separate question, answered by deptrac.layers.php.
 *
 * Run:  vendor/bin/deptrac analyse --config-file=deptrac.modules.php
 * Test: tests/Architecture/DeptracTest.php runs it inside `php artisan test`.
 */

use Deptrac\Deptrac\Contract\Config\Collector\BoolConfig;
use Deptrac\Deptrac\Contract\Config\Collector\ClassConfig;
use Deptrac\Deptrac\Contract\Config\Collector\DirectoryConfig;
use Deptrac\Deptrac\Contract\Config\DeptracConfig;
use Deptrac\Deptrac\Contract\Config\Layer;
use Deptrac\Deptrac\Contract\Config\Ruleset;

return static function (DeptracConfig $config): void {
    /**
     * The directories a module offers to the rest of the application:
     * contracts and values only, never the model itself. Order matters for
     * nothing here, but the list doubles as the negative lookahead that defines
     * every module's internals, so keep the paths module-relative.
     */
    $publicApi = [
        'Application/Contracts',
        'Application/DTOs',
        'Domain/Contracts',
        'Domain/Enums',
        'Domain/Events',
        'Domain/ValueObjects',
    ];

    /**
     * The account model is the one deliberate exception, and it is named here
     * rather than hidden in a baseline.
     *
     * `Auth\Domain\Models\User` and its SaaS subclass are not a module's
     * aggregate. They are the **tenant root**: every module already keys off
     * that row (`user_id`), every module already receives it through
     * `HasUserScope::user()`, and the identity contract every module types it
     * as (`Shared\Domain\Contracts\Account`) lives in the shared kernel. Auth
     * and Saas host the classes because the auth config and Cashier have to
     * point somewhere, not because they own the concept.
     *
     * One module manipulates that row *as rows*: Team. Inviting someone,
     * accepting an invitation, changing a role and removing a member are
     * inserts, updates and soft deletes on `users` — team membership is not
     * data Team reads from another aggregate, it *is* the aggregate, stored in
     * a table another module happens to declare. Wrapping that in a contract
     * would produce one interface with one implementation, on exactly the
     * class Team already knows, whose every method exists to hide a type.
     * That is a facade, not a boundary.
     *
     * So the rule is stated instead of excused: the account model is its own
     * layer, and Auth, Saas and Team may reach it. **Nobody else may** — which
     * is the whole of the work in ACCOUNT_MODEL_DEBT_PLAN.md, where 110
     * entries became 12. Anything genuinely account-level that another module
     * needs still goes through a narrow contract (ProvidesSupplierProfile,
     * ProvidesPlanEntitlements, CarriesTeamRole, AccountLocator, …), and a new
     * module reaching for the model still fails.
     */
    $accountModel = 'App.Modules.(Auth|Saas).Domain.Models.User$';

    $accountModelOwners = ['Auth', 'Saas', 'Team'];

    /**
     * The second named exception: a foreign key between two modules.
     *
     * `Invoice::client()`, `TimeEntry::order()`, `Trip::client()` — about two
     * dozen of them. A foreign key is a fact about the schema, not a design
     * choice a module gets to make privately: the column is there either way,
     * the database enforces it either way, and deleting the relation buys
     * nothing while costing eager loading and creating N+1 where none exists
     * today.
     *
     * So `Domain\Models` may name another module's `Domain\Models`, and
     * **only** `Domain\Models` may. An Action, a Service or a Controller
     * reaching for a foreign model is the thing this whole depfile is about
     * and stays a violation — what changes is that two dozen deliberate
     * associations stop sitting in the baseline looking exactly like two dozen
     * accidents. A baseline should hold what we mean to fix.
     *
     * Pinned by tests/Architecture/DeptracTest.php, same as the account model:
     * letting any non-model layer near a foreign model fails the build.
     */
    $modules = array_map(
        static fn (string $path): string => basename($path),
        glob(__DIR__.'/app/Modules/*', GLOB_ONLYDIR) ?: [],
    );
    sort($modules);

    $notPublic = '(?!(?:'.implode('|', $publicApi).')/)';

    /** @var array<string, Layer> $public */
    $public = [];
    /** @var array<string, Layer> $internal */
    $internal = [];
    /** @var array<string, Layer> $models */
    $models = [];

    foreach ($modules as $module) {
        if ($module === 'Shared') {
            // The shared kernel: public in full, no internals to protect.
            $public[$module] = Layer::withName('Public_Shared')->collectors(
                DirectoryConfig::create('/app/Modules/Shared/'),
            );

            continue;
        }

        $public[$module] = Layer::withName("Public_{$module}")->collectors(
            ...array_map(
                static fn (string $dir): DirectoryConfig => DirectoryConfig::create("/app/Modules/{$module}/{$dir}/"),
                $publicApi,
            ),
        );

        // Both exceptions are carved out of the host module's internals —
        // without that they would sit in two layers at once, and deptrac
        // reports a violation for every disallowed layer pair, so the
        // permitted pair would never win.
        $models[$module] = Layer::withName("Models_{$module}")->collectors(
            BoolConfig::create(
                must: [DirectoryConfig::create("/app/Modules/{$module}/Domain/Models/")],
                mustNot: [ClassConfig::create($accountModel)],
            ),
        );

        $internal[$module] = Layer::withName("Internal_{$module}")->collectors(
            BoolConfig::create(
                must: [DirectoryConfig::create("/app/Modules/{$module}/{$notPublic}")],
                mustNot: [
                    ClassConfig::create($accountModel),
                    DirectoryConfig::create("/app/Modules/{$module}/Domain/Models/"),
                ],
            ),
        );
    }

    $accountModelLayer = Layer::withName('AccountModel')->collectors(
        ClassConfig::create($accountModel),
    );

    $rulesets = [
        // Its own modules' internals, because it *is* their code — plus every
        // module's public API, like anything else.
        Ruleset::forLayer($accountModelLayer)->accesses(
            ...array_values($public),
            ...array_values($models),
            ...array_values(array_intersect_key($internal, array_flip(['Auth', 'Saas']))),
        ),
    ];

    foreach ($modules as $module) {
        // Anything may use any module's public API. A layer may always depend
        // on itself, so this is the whole of the rule: what is left over —
        // Internal_<other module> — is denied by default.
        $mayReachAccountModel = in_array($module, $accountModelOwners, true) ? [$accountModelLayer] : [];
        $ownModels = isset($models[$module]) ? [$models[$module]] : [];

        $rulesets[] = Ruleset::forLayer($public[$module])->accesses(
            ...array_values($public),
            ...$mayReachAccountModel,
            ...$ownModels,
            ...(isset($internal[$module]) ? [$internal[$module]] : []),
        );

        if (isset($internal[$module])) {
            $rulesets[] = Ruleset::forLayer($internal[$module])->accesses(
                ...array_values($public),
                ...$mayReachAccountModel,
                ...$ownModels,
            );
        }

        if (isset($models[$module])) {
            // Every module's models — the exception — plus its own module's
            // internals, because a model is that module's own code.
            $rulesets[] = Ruleset::forLayer($models[$module])->accesses(
                ...array_values($public),
                ...array_values($models),
                ...$mayReachAccountModel,
                ...(isset($internal[$module]) ? [$internal[$module]] : []),
            );
        }
    }

    $config
        ->cacheFile(__DIR__.'/storage/framework/cache/deptrac.modules.cache')
        ->baseline(__DIR__.'/deptrac.modules.baseline.yaml')
        ->paths(__DIR__.'/app')
        ->layers($accountModelLayer, ...array_values($public), ...array_values($models), ...array_values($internal))
        ->rulesets(...$rulesets);
};
