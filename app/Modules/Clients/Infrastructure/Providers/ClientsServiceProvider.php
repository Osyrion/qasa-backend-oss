<?php

declare(strict_types=1);

namespace App\Modules\Clients\Infrastructure\Providers;

use App\Modules\Clients\Application\Actions\FetchCompanyDataAction;
use App\Modules\Clients\Application\Contracts\ClientRepositoryInterface;
use App\Modules\Clients\Application\Contracts\VatPayerAccountRegistryInterface;
use App\Modules\Clients\Application\Contracts\VatValidatorInterface;
use App\Modules\Clients\Domain\Models\Client;
use App\Modules\Clients\Infrastructure\Clients\AresApiClient;
use App\Modules\Clients\Infrastructure\Clients\CrpdphApiClient;
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

        $this->app->bind(
            VatValidatorInterface::class,
            ViesApiClient::class,
        );

        $this->app->bind(
            VatPayerAccountRegistryInterface::class,
            CrpdphApiClient::class,
        );

        $this->app->bind(FetchCompanyDataAction::class, fn (): FetchCompanyDataAction => new FetchCompanyDataAction([
            'CZ' => $this->app->make(AresApiClient::class),
            'SK' => $this->app->make(RpoApiClient::class),
        ]));
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../../Presentation/Routes/clients.php');

        Gate::policy(Client::class, ClientPolicy::class);
    }
}
