<?php

declare(strict_types=1);

namespace App\Modules\Integrations\Infrastructure\Providers;

use App\Modules\Integrations\Application\Listeners\DispatchWebhooks;
use App\Modules\Integrations\Domain\Models\WebhookEndpoint;
use App\Modules\Integrations\Presentation\Console\PurgeWebhookDeliveriesCommand;
use App\Modules\Integrations\Presentation\Policies\WebhookEndpointPolicy;
use App\Modules\Invoicing\Domain\Events\InboxItemCreated;
use App\Modules\Invoicing\Domain\Events\InvoiceCreated;
use App\Modules\Invoicing\Domain\Events\InvoiceOverdue;
use App\Modules\Invoicing\Domain\Events\InvoicePaid;
use App\Modules\Invoicing\Domain\Events\InvoiceReminded;
use App\Modules\Invoicing\Domain\Events\InvoiceSent;
use App\Modules\Invoicing\Domain\Events\PaymentRecorded;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class IntegrationsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(base_path('routes/integrations.php'));

        Gate::policy(WebhookEndpoint::class, WebhookEndpointPolicy::class);

        foreach ([
            InvoiceCreated::class,
            InvoiceSent::class,
            InvoicePaid::class,
            InvoiceReminded::class,
            InvoiceOverdue::class,
            PaymentRecorded::class,
            InboxItemCreated::class,
        ] as $event) {
            Event::listen($event, DispatchWebhooks::class);
        }

        if ($this->app->runningInConsole()) {
            $this->commands([PurgeWebhookDeliveriesCommand::class]);
        }
    }
}
