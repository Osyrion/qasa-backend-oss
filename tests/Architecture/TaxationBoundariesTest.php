<?php

declare(strict_types=1);

/**
 * Fixes the architectural boundaries introduced in the Taxation module
 * refactor (docs/plans/TAX_RESIDENCY_SEPARATION_PLAN.md fázy 2-3) so they
 * can't be silently broken later.
 */

// ── 1. SK ↮ CZ isolation ──────────────────────────────────────────────────
//
// Sadzby, prahy, sekcie výkazov, texty doložiek a labely sa zámerne
// duplikujú medzi SkTaxSystem a CzTaxSystem — a change to SK legislation
// must never be able to reach into CZ code (or vice versa) via a shared
// import, since that's exactly the coupling the duplication is meant to
// avoid.

arch("Taxation's Sk infrastructure does not import its Cz infrastructure")
    ->expect('App\Modules\Taxation\Infrastructure\Sk')
    ->not->toUse('App\Modules\Taxation\Infrastructure\Cz');

arch("Taxation's Cz infrastructure does not import its Sk infrastructure")
    ->expect('App\Modules\Taxation\Infrastructure\Cz')
    ->not->toUse('App\Modules\Taxation\Infrastructure\Sk');

// ── 2. Access only through contracts ────────────────────────────────────
//
// Every other module must resolve tax logic via TaxSystemResolverInterface
// / the Domain\Contracts interfaces — never reach into a Sk*/Cz*-named
// class directly. This is about the class NAME prefix, not the whole
// Taxation\Infrastructure\{Sk,Cz} namespace: builders that physically live
// there but keep their original, unprefixed names (KvDphXmlBuilder,
// DphKh1XmlBuilder, OmegaExportBuilder, PohodaXmlBuilder, IsdocBuilder) are
// a deliberate exception — InvoiceExportController/VatReportController
// inject them directly by format, per fáza 2. Scoping expect() to
// "App\Modules\{$module}" already excludes tests (no App\Modules\* namespace)
// and Taxation itself (TaxationServiceProvider legitimately constructs
// Sk/CzTaxSystem to bind the resolver — it's inside Taxation, not "outside").

$modulesPath = dirname(__DIR__, 2).'/app/Modules';

$skCzClasses = collect(glob($modulesPath.'/Taxation/Infrastructure/{Sk,Cz}/*.php', GLOB_BRACE) ?: [])
    ->map(fn (string $path): string => pathinfo($path, PATHINFO_FILENAME))
    ->filter(fn (string $class): bool => str_starts_with($class, 'Sk') || str_starts_with($class, 'Cz'))
    ->map(fn (string $class): string => 'App\Modules\Taxation\Infrastructure\\'.(str_starts_with($class, 'Sk') ? 'Sk' : 'Cz').'\\'.$class)
    ->values()
    ->all();

$otherModules = collect(glob($modulesPath.'/*', GLOB_ONLYDIR) ?: [])
    ->map(fn (string $path): string => basename($path))
    ->reject(fn (string $module): bool => in_array($module, ['Taxation', 'Shared'], true))
    ->values();

foreach ($otherModules as $module) {
    arch("{$module} module does not use a Sk*/Cz* Taxation class directly")
        ->expect("App\\Modules\\{$module}")
        ->not->toUse($skCzClasses);
}

// ── 3. OSS core purity ───────────────────────────────────────────────────
//
// The whole Taxation module is designed to port 1:1 into qasa_core (fáza
// 6) — it must never depend on the SaaS-only Saas/Subscriptions modules.

arch('Taxation module does not import Saas or Subscriptions')
    ->expect('App\Modules\Taxation')
    ->not->toUse([
        'App\Modules\Saas',
        'App\Modules\Subscriptions',
    ]);
