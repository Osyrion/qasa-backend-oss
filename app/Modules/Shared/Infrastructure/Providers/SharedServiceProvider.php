<?php

declare(strict_types=1);

namespace App\Modules\Shared\Infrastructure\Providers;

use App\Modules\Clients\Domain\Models\Client;
use App\Modules\Invoicing\Domain\Models\Invoice;
use App\Modules\Invoicing\Domain\Models\Quote;
use App\Modules\Orders\Domain\Models\Order;
use App\Modules\Shared\Application\Contracts\ActivityRecorderInterface;
use App\Modules\Shared\Application\Listeners\RecordActivity;
use App\Modules\Shared\Domain\Models\ActivityLog;
use App\Modules\Shared\Infrastructure\Repositories\EloquentActivityRecorder;
use App\Modules\Shared\Presentation\Console\PurgeActivityLogCommand;
use App\Modules\Shared\Presentation\Console\PurgeIdempotencyKeysCommand;
use App\Modules\Shared\Presentation\Policies\ActivityLogPolicy;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class SharedServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(ActivityRecorderInterface::class, EloquentActivityRecorder::class);
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../../Presentation/Routes/activity.php');
        $this->loadRoutesFrom(__DIR__.'/../../Presentation/Routes/health.php');

        // Non-enforcing: only aliases these 4 models for readable
        // subject_type values. enforceMorphMap() would require every
        // polymorphic relation app-wide (Sanctum's tokenable,
        // notifications' notifiable, ...) to be registered here too.
        Relation::morphMap([
            'client' => Client::class,
            'order' => Order::class,
            'invoice' => Invoice::class,
            'quote' => Quote::class,
        ]);

        Gate::policy(ActivityLog::class, ActivityLogPolicy::class);

        foreach (RecordActivity::events() as $event) {
            Event::listen($event, RecordActivity::class);
        }

        if ($this->app->runningInConsole()) {
            $this->commands([PurgeActivityLogCommand::class, PurgeIdempotencyKeysCommand::class]);
        }
    }
}
