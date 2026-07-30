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

$premiumNamespaces = array_map(
    static fn (string $module): string => "App\\Modules\\{$module}",
    ['Accounting', 'Admin', 'Automation', 'Banking', 'Calendar', 'Integrations', 'Logbook', 'Pricing', 'Reports', 'Saas', 'Subscriptions', 'Team', 'TimeTracking'],
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
