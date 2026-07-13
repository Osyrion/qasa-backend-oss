<?php

declare(strict_types=1);

namespace App\Modules\Calendar\Infrastructure\Providers;

use App\Modules\Calendar\Application\Actions\ImportEventsCsvAction;
use App\Modules\Calendar\Application\Contracts\EventRepositoryInterface;
use App\Modules\Calendar\Application\Contracts\OverlapPolicyInterface;
use App\Modules\Calendar\Application\Services\AllowOverlapPolicy;
use App\Modules\Calendar\Application\Services\EventTimeNormalizer;
use App\Modules\Calendar\Domain\Models\Event;
use App\Modules\Calendar\Infrastructure\Csv\QasaEventCsvParser;
use App\Modules\Calendar\Infrastructure\Repositories\EloquentEventRepository;
use App\Modules\Calendar\Presentation\Console\PurgePastEventsCommand;
use App\Modules\Calendar\Presentation\Policies\EventPolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class CalendarServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(EventRepositoryInterface::class, EloquentEventRepository::class);
        $this->app->bind(OverlapPolicyInterface::class, AllowOverlapPolicy::class);

        $this->app->bind(ImportEventsCsvAction::class, function (): ImportEventsCsvAction {
            return new ImportEventsCsvAction(
                repository: $this->app->make(EventRepositoryInterface::class),
                overlapPolicy: $this->app->make(OverlapPolicyInterface::class),
                normalizer: $this->app->make(EventTimeNormalizer::class),
                parsers: [new QasaEventCsvParser],
            );
        });
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../../Presentation/Routes/calendar.php');

        Gate::policy(Event::class, EventPolicy::class);

        if ($this->app->runningInConsole()) {
            $this->commands([PurgePastEventsCommand::class]);
        }
    }
}
