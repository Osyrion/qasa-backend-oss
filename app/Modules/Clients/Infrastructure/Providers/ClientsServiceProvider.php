<?php

declare(strict_types=1);

namespace App\Modules\Clients\Infrastructure\Providers;

use App\Modules\Clients\Application\Actions\AnonymizeClientAction;
use App\Modules\Clients\Application\Actions\CreateClientAction;
use App\Modules\Clients\Application\Actions\FetchCompanyDataAction;
use App\Modules\Clients\Application\Contracts\ClientAuthorization;
use App\Modules\Clients\Application\Contracts\ClientAutoSendPreference;
use App\Modules\Clients\Application\Contracts\ClientBankAccountLearner;
use App\Modules\Clients\Application\Contracts\ClientDirectory;
use App\Modules\Clients\Application\Contracts\ClientImportRegistry;
use App\Modules\Clients\Application\Contracts\ClientPortalDirectory;
use App\Modules\Clients\Application\Contracts\ClientRepositoryInterface;
use App\Modules\Clients\Application\Contracts\ClientRepresentation;
use App\Modules\Clients\Application\Contracts\ClientUsageGuardInterface;
use App\Modules\Clients\Application\Contracts\ClientUsagePolicyInterface;
use App\Modules\Clients\Application\Contracts\ClientUsageRoster;
use App\Modules\Clients\Application\Contracts\ClientVatVerification;
use App\Modules\Clients\Application\Contracts\CompanyRegistryLookup;
use App\Modules\Clients\Application\Contracts\CreateClientActionInterface;
use App\Modules\Clients\Application\Contracts\VatValidatorInterface;
use App\Modules\Clients\Application\Services\AlwaysUsableClientPolicy;
use App\Modules\Clients\Application\Services\ClientLinkableRecords;
use App\Modules\Clients\Application\Services\ClientsAccountData;
use App\Modules\Clients\Application\Services\ClientUsageGuard;
use App\Modules\Clients\Application\Services\EloquentClientAutoSendPreference;
use App\Modules\Clients\Application\Services\EloquentClientBankAccountLearner;
use App\Modules\Clients\Application\Services\EloquentClientDirectory;
use App\Modules\Clients\Application\Services\EloquentClientImportRegistry;
use App\Modules\Clients\Application\Services\EloquentClientPortalDirectory;
use App\Modules\Clients\Application\Services\EloquentClientUsageRoster;
use App\Modules\Clients\Application\Services\EloquentClientVatVerification;
use App\Modules\Clients\Application\Services\GateClientAuthorization;
use App\Modules\Clients\Domain\Models\Client;
use App\Modules\Clients\Infrastructure\Clients\AresApiClient;
use App\Modules\Clients\Infrastructure\Clients\RpoApiClient;
use App\Modules\Clients\Infrastructure\Clients\ViesApiClient;
use App\Modules\Clients\Infrastructure\Repositories\EloquentClientRepository;
use App\Modules\Clients\Presentation\Policies\ClientPolicy;
use App\Modules\Clients\Presentation\Support\ClientResourceRepresentation;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class ClientsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(CompanyRegistryLookup::class, FetchCompanyDataAction::class);
        // Other modules ask about a client through this, never by querying the
        // clients table themselves.
        // Clients' section of the GDPR account export — Auth assembles the
        // payload but must not know what a client row looks like.
        $this->app->tag([ClientsAccountData::class], ['account.export']);
        $this->app->tag([ClientLinkableRecords::class], ['linkable.records']);

        $this->app->bind(ClientDirectory::class, EloquentClientDirectory::class);
        $this->app->bind(ClientUsageRoster::class, EloquentClientUsageRoster::class);
        $this->app->bind(ClientVatVerification::class, EloquentClientVatVerification::class);
        $this->app->bind(ClientAutoSendPreference::class, EloquentClientAutoSendPreference::class);
        $this->app->bind(ClientAuthorization::class, GateClientAuthorization::class);
        $this->app->bind(ClientPortalDirectory::class, EloquentClientPortalDirectory::class);
        $this->app->bind(ClientImportRegistry::class, EloquentClientImportRegistry::class);

        // …and renders one through this, never by embedding our resource.
        $this->app->bind(ClientRepresentation::class, ClientResourceRepresentation::class);
        $this->app->bind(
            ClientRepositoryInterface::class,
            EloquentClientRepository::class,
        );

        // Banking learns a client's account from a confirmed payment match
        // and writes it through here, rather than through the aggregate.
        $this->app->bind(
            ClientBankAccountLearner::class,
            EloquentClientBankAccountLearner::class,
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
