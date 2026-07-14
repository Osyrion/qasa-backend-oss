<?php

use App\Modules\Auth\Infrastructure\Providers\AuthServiceProvider;
use App\Modules\Clients\Infrastructure\Providers\ClientsServiceProvider;
use App\Modules\Invoicing\Infrastructure\Providers\InvoicingServiceProvider;
use App\Modules\Orders\Infrastructure\Providers\OrdersServiceProvider;
use App\Modules\Shared\Infrastructure\Providers\SharedServiceProvider;
use App\Providers\AppServiceProvider;

$providers = [
    AppServiceProvider::class,
    SharedServiceProvider::class,
    AuthServiceProvider::class,
    ClientsServiceProvider::class,
    OrdersServiceProvider::class,
    InvoicingServiceProvider::class,
];

// Optional edition overlay — a deployment can contribute extra providers.
// All register() calls run before any boot(), so an overlay can adjust
// config and the User model early enough.
if (file_exists(__DIR__.'/providers.edition.php')) {
    $providers = array_merge($providers, require __DIR__.'/providers.edition.php');
}

return $providers;
