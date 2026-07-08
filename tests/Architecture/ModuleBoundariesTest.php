<?php

declare(strict_types=1);

/**
 * A module's Application layer may only reach into another module through
 * its Application\Contracts (interfaces bound in that module's
 * ServiceProvider) or its Domain\Models — never another module's concrete
 * Application\Services, Application\Actions, or Infrastructure classes.
 * That would bypass the DI boundary and couple us to implementation details
 * that are free to change inside the owning module.
 */
$modulesPath = dirname(__DIR__, 2).'/app/Modules';

$modules = collect(glob($modulesPath.'/*', GLOB_ONLYDIR) ?: [])
    ->map(fn (string $path): string => basename($path))
    ->reject(fn (string $module): bool => $module === 'Shared')
    ->values();

foreach ($modules as $module) {
    $forbidden = $modules
        ->reject(fn (string $other): bool => $other === $module)
        ->flatMap(fn (string $other): array => [
            "App\\Modules\\{$other}\\Application\\Services",
            "App\\Modules\\{$other}\\Application\\Actions",
            "App\\Modules\\{$other}\\Infrastructure",
        ])
        ->all();

    arch("{$module} module's Application layer only depends on other modules via their Contracts")
        ->expect("App\\Modules\\{$module}\\Application")
        ->not->toUse($forbidden);
}
