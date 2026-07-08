<?php

declare(strict_types=1);

namespace App\Modules\TimeTracking\Infrastructure\Providers;

use App\Modules\TimeTracking\Application\Actions\ImportCsvAction;
use App\Modules\TimeTracking\Application\Contracts\ClockifyClientInterface;
use App\Modules\TimeTracking\Application\Contracts\CnbRateClientInterface;
use App\Modules\TimeTracking\Application\Contracts\ExchangeRateServiceInterface;
use App\Modules\TimeTracking\Application\Contracts\ExpenseRepositoryInterface;
use App\Modules\TimeTracking\Application\Contracts\WorkLogRepositoryInterface;
use App\Modules\TimeTracking\Application\Services\ExchangeRateService;
use App\Modules\TimeTracking\Domain\Models\Expense;
use App\Modules\TimeTracking\Domain\Models\TimeEntry;
use App\Modules\TimeTracking\Infrastructure\Clients\ClockifyApiClient;
use App\Modules\TimeTracking\Infrastructure\Clients\CnbApiRateClient;
use App\Modules\TimeTracking\Infrastructure\Csv\ClockifyCsvParser;
use App\Modules\TimeTracking\Infrastructure\Csv\TogglCsvParser;
use App\Modules\TimeTracking\Infrastructure\Repositories\EloquentExpenseRepository;
use App\Modules\TimeTracking\Infrastructure\Repositories\EloquentWorkLogRepository;
use App\Modules\TimeTracking\Presentation\Policies\ExpensePolicy;
use App\Modules\TimeTracking\Presentation\Policies\TimeEntryPolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class TimeTrackingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(
            WorkLogRepositoryInterface::class,
            EloquentWorkLogRepository::class,
        );

        $this->app->bind(
            ExpenseRepositoryInterface::class,
            EloquentExpenseRepository::class,
        );

        $this->app->bind(
            CnbRateClientInterface::class,
            CnbApiRateClient::class,
        );

        $this->app->bind(
            ClockifyClientInterface::class,
            ClockifyApiClient::class,
        );

        $this->app->bind(
            ExchangeRateServiceInterface::class,
            ExchangeRateService::class,
        );

        $this->app->bind(ImportCsvAction::class, function (): ImportCsvAction {
            return new ImportCsvAction(
                repository: $this->app->make(WorkLogRepositoryInterface::class),
                parsers: [
                    new TogglCsvParser,
                    new ClockifyCsvParser,
                ],
            );
        });
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(base_path('routes/time-tracking.php'));

        Gate::policy(TimeEntry::class, TimeEntryPolicy::class);
        Gate::policy(Expense::class, ExpensePolicy::class);
    }
}
