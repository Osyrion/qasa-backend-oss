<?php

use App\Modules\Auth\Infrastructure\Providers\AuthServiceProvider;
use App\Modules\Clients\Infrastructure\Providers\ClientsServiceProvider;
use App\Modules\Invoicing\Infrastructure\Providers\InvoicingServiceProvider;
use App\Modules\Orders\Infrastructure\Providers\OrdersServiceProvider;
use App\Modules\Shared\Infrastructure\Providers\SharedServiceProvider;
use App\Modules\Taxation\Infrastructure\Providers\TaxationServiceProvider;
use App\Providers\AppServiceProvider;

$providers = [
    AppServiceProvider::class,
    AuthServiceProvider::class,
    ClientsServiceProvider::class,
    OrdersServiceProvider::class,
    InvoicingServiceProvider::class,
    SharedServiceProvider::class,
    TaxationServiceProvider::class,
];

// Edition overlay (SaaS repo only) — contributes the Saas/Team/Admin
// providers. All register() calls run before any boot(), so the overlay
// switches the edition config and User model early enough.
if (file_exists(__DIR__.'/providers.edition.php')) {
    $providers = array_merge($providers, require __DIR__.'/providers.edition.php');
}

return $providers;
