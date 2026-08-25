<?php

declare(strict_types=1);

/**
 * Dependency *direction* inside a module (the DDD/Clean-Architecture layering
 * this codebase is built on — see CLAUDE.md § Architecture).
 *
 *     Presentation ──┐
 *                    ├──> Application ──> Domain
 *     Infrastructure ┘
 *
 * Read it the other way round and it is the rule that matters: Domain knows
 * nothing but itself, Application knows only Domain, and the two outer layers
 * know the two inner ones but never each other — a controller must not reach
 * for an Eloquent repository, and a Stripe client must not reach for a
 * Resource.
 *
 * The layers are deliberately module-agnostic: every module's `Domain` is the
 * same "Domain" layer here, so the rules hold for all twenty modules and for
 * any module added later without editing this file. *Cross-module* coupling is
 * a separate question, answered by deptrac.modules.php.
 *
 * Collectors match on file path rather than namespace on purpose: a namespace
 * regex has to escape backslashes twice over and silently collects nothing
 * when it gets that wrong (a broken pattern and a clean layer look identical
 * in the report). Paths have no such trap.
 *
 * Run:  vendor/bin/deptrac analyse --config-file=deptrac.layers.php
 * Test: tests/Architecture/DeptracTest.php runs it inside `php artisan test`.
 */

use Deptrac\Deptrac\Contract\Config\Collector\DirectoryConfig;
use Deptrac\Deptrac\Contract\Config\DeptracConfig;
use Deptrac\Deptrac\Contract\Config\Layer;
use Deptrac\Deptrac\Contract\Config\Ruleset;

return static function (DeptracConfig $config): void {
    /** Matches `<any module>/<$tail>` under app/Modules. */
    $inEveryModule = static fn (string $tail): DirectoryConfig => DirectoryConfig::create(
        '/app/Modules/[^/]+/'.$tail
    );

    $config
        ->cacheFile(__DIR__.'/storage/framework/cache/deptrac.layers.cache')
        ->baseline(__DIR__.'/deptrac.layers.baseline.yaml')
        ->paths(__DIR__.'/app')
        ->layers(
            $domain = Layer::withName('Domain')->collectors(
                $inEveryModule('Domain/'),
            ),
            $application = Layer::withName('Application')->collectors(
                $inEveryModule('Application/'),
            ),
            // A service provider is the composition root: wiring a controller
            // to an action to a repository is its entire job, so it is exempt
            // from the direction rules rather than an exception to them.
            $providers = Layer::withName('Providers')->collectors(
                $inEveryModule('Infrastructure/Providers/'),
            ),
            $infrastructure = Layer::withName('Infrastructure')->collectors(
                $inEveryModule('Infrastructure/(?!Providers/)'),
            ),
            $presentation = Layer::withName('Presentation')->collectors(
                $inEveryModule('Presentation/'),
            ),
            // Migrations are anonymous classes with no name to collect; this
            // is the seeders, which legitimately build domain models.
            $database = Layer::withName('Database')->collectors(
                $inEveryModule('Database/'),
            ),
        )
        ->rulesets(
            Ruleset::forLayer($domain),
            Ruleset::forLayer($application)->accesses($domain),
            Ruleset::forLayer($infrastructure)->accesses($domain, $application),
            Ruleset::forLayer($presentation)->accesses($domain, $application),
            Ruleset::forLayer($database)->accesses($domain, $application),
            Ruleset::forLayer($providers)->accesses(
                $domain, $application, $infrastructure, $presentation, $database,
            ),
        );
};
