<?php

use App\Modules\Shared\Presentation\Middleware\SetLocale;
use App\Providers\AppServiceProvider;
use App\Providers\TelescopeServiceProvider;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withProviders([
        AppServiceProvider::class,
        TelescopeServiceProvider::class,
    ])
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->append(SetLocale::class);

        // Edition overlay (SaaS repo only) — spatie middleware aliases.
        if (file_exists(__DIR__.'/middleware.edition.php')) {
            (require __DIR__.'/middleware.edition.php')($middleware);
        }
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
