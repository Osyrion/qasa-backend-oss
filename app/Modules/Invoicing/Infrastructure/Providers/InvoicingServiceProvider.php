<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Infrastructure\Providers;

use App\Modules\Invoicing\Application\Contracts\BankAccountRepositoryInterface;
use App\Modules\Invoicing\Application\Contracts\InvoiceRepositoryInterface;
use App\Modules\Invoicing\Application\Contracts\RecurringInvoiceTemplateRepositoryInterface;
use App\Modules\Invoicing\Domain\Models\BankAccount;
use App\Modules\Invoicing\Domain\Models\Invoice;
use App\Modules\Invoicing\Domain\Models\RecurringInvoiceTemplate;
use App\Modules\Invoicing\Infrastructure\Repositories\EloquentBankAccountRepository;
use App\Modules\Invoicing\Infrastructure\Repositories\EloquentInvoiceRepository;
use App\Modules\Invoicing\Infrastructure\Repositories\EloquentRecurringInvoiceTemplateRepository;
use App\Modules\Invoicing\Presentation\Console\GenerateRecurringInvoicesCommand;
use App\Modules\Invoicing\Presentation\Policies\BankAccountPolicy;
use App\Modules\Invoicing\Presentation\Policies\InvoicePolicy;
use App\Modules\Invoicing\Presentation\Policies\RecurringInvoiceTemplatePolicy;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class InvoicingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(
            InvoiceRepositoryInterface::class,
            EloquentInvoiceRepository::class,
        );

        $this->app->bind(
            BankAccountRepositoryInterface::class,
            EloquentBankAccountRepository::class,
        );

        $this->app->bind(
            RecurringInvoiceTemplateRepositoryInterface::class,
            EloquentRecurringInvoiceTemplateRepository::class,
        );
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(base_path('routes/invoicing.php'));

        // Register Blade views for PDF
        $this->loadViewsFrom(__DIR__.'/../Views', 'invoices');

        // Translations for the PDF template (cs/sk/en)
        $this->loadTranslationsFrom(__DIR__.'/../Lang', 'invoices');

        // Outbound email is abusable (free-form to/cc), so cap it per account.
        RateLimiter::for('invoice-email', function (Request $request): Limit {
            return Limit::perMinute(10)->by(
                (string) ($request->user()?->getAuthIdentifier() ?? $request->ip()),
            );
        });

        Gate::policy(Invoice::class, InvoicePolicy::class);
        Gate::policy(BankAccount::class, BankAccountPolicy::class);
        Gate::policy(RecurringInvoiceTemplate::class, RecurringInvoiceTemplatePolicy::class);

        if ($this->app->runningInConsole()) {
            $this->commands([GenerateRecurringInvoicesCommand::class]);
        }
    }
}
