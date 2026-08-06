<?php

declare(strict_types=1);

/**
 * The OSS edition is produced by deleting the premium module directories from
 * this repository (see docs/plans/OSS_CORE_GENERATED_RELEASE_PLAN.md). That is
 * only mechanical while no OSS module names a premium class: the moment one
 * does, the generated core stops booting and the two editions drift back into
 * a hand-maintained fork.
 *
 * Premium features reach into OSS modules the other way round — through a
 * contract bound in the OSS module, an event the premium module listens for,
 * or a relation the premium module attaches at runtime. Never through a
 * direct import.
 */
$ossModules = ['Auth', 'Clients', 'Invoicing', 'Orders', 'Shared', 'Taxation'];

/*
 * Must stay in step with PREMIUM_MODULES in scripts/build-oss.sh — that list
 * is what actually gets deleted, this one is what stops a core module naming
 * it first. `Documents` was in the script and missing here, so an import of
 * App\Modules\Documents from a core module would have passed the build and
 * broken only in the generated OSS tree, where the directory no longer exists.
 * The count guard below is the standing check.
 */
$premiumModules = ['Accounting', 'Admin', 'Automation', 'Banking', 'Calendar', 'Documents', 'Integrations', 'Logbook', 'Pricing', 'Reports', 'Saas', 'Subscriptions', 'Team', 'TimeTracking'];

$premiumNamespaces = array_map(
    static fn (string $module): string => "App\\Modules\\{$module}",
    $premiumModules,
);

foreach ($ossModules as $module) {
    arch("{$module} module does not depend on a premium module")
        ->expect("App\\Modules\\{$module}")
        ->not->toUse($premiumNamespaces);
}

/*
 * Code outside app/Modules ships in the OSS build too, and it is where the
 * first leak actually hid: App\Mcp\Tools\RevenueReportTool imported the
 * premium Reports module while every module-scoped check was green.
 */
foreach (['Mcp', 'Http', 'Providers'] as $namespace) {
    arch("App\\{$namespace} does not depend on a premium module")
        ->expect("App\\{$namespace}")
        ->not->toUse($premiumNamespaces);
}

/*
 * Two lists, one boundary: the arrays above decide what may be *named*, and
 * scripts/build-oss.sh decides what actually gets *deleted*. Drift between
 * them is silent in both directions — a module the script deletes but this
 * file does not guard breaks only in the generated tree, and a module guarded
 * here but not deleted ships premium code into the AGPL build.
 *
 * The script is stripped from the OSS build itself (`rm -rf scripts`), so the
 * comparison only runs where the file exists.
 */
// Plain paths rather than base_path()/app_path(): this file runs the arch()
// expectations without booting the application, and the helpers need a
// container.
$repoRoot = dirname(__DIR__, 2);

it('classifies every module the same way scripts/build-oss.sh does', function () use ($premiumModules, $repoRoot): void {
    $script = $repoRoot.'/scripts/build-oss.sh';

    if (! file_exists($script)) {
        expect(true)->toBeTrue(); // OSS build: the script it compares against is gone.

        return;
    }

    $source = (string) file_get_contents($script);

    if (preg_match('/PREMIUM_MODULES=\((.*?)\)/s', $source, $matches) !== 1) {
        throw new RuntimeException('Could not find PREMIUM_MODULES in scripts/build-oss.sh — the parser here needs updating.');
    }

    $inScript = preg_split('/\s+/', trim($matches[1]), flags: PREG_SPLIT_NO_EMPTY) ?: [];

    sort($inScript);
    $expected = $premiumModules;
    sort($expected);

    expect($inScript)->toBe($expected);
});

it('classifies every module in app/Modules as either core or premium', function () use ($premiumModules, $ossModules, $repoRoot): void {
    $onDisk = array_map(
        static fn (string $path): string => basename($path),
        glob($repoRoot.'/app/Modules/*', GLOB_ONLYDIR) ?: [],
    );

    $unclassified = array_values(array_diff($onDisk, $ossModules, $premiumModules));

    expect($unclassified)->toBe(
        [],
        'These modules are in neither $ossModules nor $premiumModules, so nothing decides which '.
        'edition they ship in: '.implode(', ', $unclassified),
    );
});
