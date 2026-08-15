<?php

declare(strict_types=1);

namespace App\Modules\Clients\Infrastructure\Providers;

use App\Modules\Clients\Application\Actions\AnonymizeClientAction;
use App\Modules\Clients\Application\Actions\CreateClientAction;
use App\Modules\Clients\Application\Actions\FetchCompanyDataAction;
use App\Modules\Clients\Application\Contracts\ClientRepositoryInterface;
use App\Modules\Clients\Application\Contracts\ClientUsageGuardInterface;
use App\Modules\Clients\Application\Contracts\ClientUsagePolicyInterface;
use App\Modules\Clients\Application\Contracts\CreateClientActionInterface;
use App\Modules\Clients\Application\Contracts\VatValidatorInterface;
use App\Modules\Clients\Application\Services\AlwaysUsableClientPolicy;
use App\Modules\Clients\Application\Services\ClientUsageGuard;
use App\Modules\Clients\Domain\Models\Client;
use App\Modules\Clients\Infrastructure\Clients\AresApiClient;
use App\Modules\Clients\Infrastructure\Clients\RpoApiClient;
use App\Modules\Clients\Infrastructure\Clients\ViesApiClient;
use App\Modules\Clients\Infrastructure\Repositories\EloquentClientRepository;
use App\Modules\Clients\Presentation\Policies\ClientPolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class ClientsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(
            ClientRepositoryInterface::class,
            EloquentClientRepository::class,
        );

        // Premium modules hang their own columns off the core clients table
        // and clear them here — the tag is empty in the OSS edition, where
        // those columns do not exist at all.
        $this->app->bind(
            AnonymizeClientAction::class,
            fn ($app): AnonymizeClientAction => new AnonymizeClientAction($app->tagged('client.anonymize')),
        );

        $this->app->bind(
            VatValidatorInterface::class,
            ViesApiClient::class,
        );

        // Scoped: the SaaS policy memoizes per-owner lookups within a request.
        $this->app->scoped(
            ClientUsagePolicyInterface::class,
            AlwaysUsableClientPolicy::class,
        );

        $this->app->bind(
            ClientUsageGuardInterface::class,
            ClientUsageGuard::class,
        );

        $this->app->bind(
            CreateClientActionInterface::class,
            CreateClientAction::class,
        );

        $this->app->bind(FetchCompanyDataAction::class, fn (): FetchCompanyDataAction => new FetchCompanyDataAction([
            'CZ' => $this->app->make(AresApiClient::class),
            'SK' => $this->app->make(RpoApiClient::class),
        ]));
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(base_path('routes/clients.php'));

        Gate::policy(Client::class, ClientPolicy::class);
    }
}
