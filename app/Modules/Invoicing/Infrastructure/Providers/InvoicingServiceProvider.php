<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Infrastructure\Providers;

use App\Modules\Auth\Domain\Events\UserRegistered;
use App\Modules\Invoicing\Application\Contracts\BankAccountRepositoryInterface;
use App\Modules\Invoicing\Application\Contracts\InvoiceInboxRepositoryInterface;
use App\Modules\Invoicing\Application\Contracts\InvoiceRepositoryInterface;
use App\Modules\Invoicing\Application\Contracts\PaymentOrderRepositoryInterface;
use App\Modules\Invoicing\Application\Contracts\QuoteRepositoryInterface;
use App\Modules\Invoicing\Application\Contracts\RecurringInvoiceTemplateRepositoryInterface;
use App\Modules\Invoicing\Application\Contracts\SupplierInvoiceRepositoryInterface;
use App\Modules\Invoicing\Application\Listeners\SeedVatRatesForNewUser;
use App\Modules\Invoicing\Application\Listeners\SendQuoteDecisionNotification;
use App\Modules\Invoicing\Domain\Contracts\InvoiceTextExtractor;
use App\Modules\Invoicing\Domain\Events\QuoteAccepted;
use App\Modules\Invoicing\Domain\Events\QuoteRejected;
use App\Modules\Invoicing\Domain\Models\BankAccount;
use App\Modules\Invoicing\Domain\Models\Invoice;
use App\Modules\Invoicing\Domain\Models\InvoiceInboxItem;
use App\Modules\Invoicing\Domain\Models\PaymentOrder;
use App\Modules\Invoicing\Domain\Models\Quote;
use App\Modules\Invoicing\Domain\Models\RecurringInvoiceTemplate;
use App\Modules\Invoicing\Domain\Models\SupplierInvoice;
use App\Modules\Invoicing\Domain\Models\VatRate;
use App\Modules\Invoicing\Infrastructure\Ocr\CompositeExtractor;
use App\Modules\Invoicing\Infrastructure\Repositories\EloquentBankAccountRepository;
use App\Modules\Invoicing\Infrastructure\Repositories\EloquentInvoiceInboxRepository;
use App\Modules\Invoicing\Infrastructure\Repositories\EloquentInvoiceRepository;
use App\Modules\Invoicing\Infrastructure\Repositories\EloquentPaymentOrderRepository;
use App\Modules\Invoicing\Infrastructure\Repositories\EloquentQuoteRepository;
use App\Modules\Invoicing\Infrastructure\Repositories\EloquentRecurringInvoiceTemplateRepository;
use App\Modules\Invoicing\Infrastructure\Repositories\EloquentSupplierInvoiceRepository;
use App\Modules\Invoicing\Presentation\Console\BackfillVatRatesCommand;
use App\Modules\Invoicing\Presentation\Console\GenerateRecurringInvoicesCommand;
use App\Modules\Invoicing\Presentation\Console\ScanInboxCommand;
use App\Modules\Invoicing\Presentation\Console\SendAutoRemindersCommand;
use App\Modules\Invoicing\Presentation\Policies\BankAccountPolicy;
use App\Modules\Invoicing\Presentation\Policies\InvoiceInboxItemPolicy;
use App\Modules\Invoicing\Presentation\Policies\InvoicePolicy;
use App\Modules\Invoicing\Presentation\Policies\PaymentOrderPolicy;
use App\Modules\Invoicing\Presentation\Policies\QuotePolicy;
use App\Modules\Invoicing\Presentation\Policies\RecurringInvoiceTemplatePolicy;
use App\Modules\Invoicing\Presentation\Policies\SupplierInvoicePolicy;
use App\Modules\Invoicing\Presentation\Policies\VatRatePolicy;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
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

        $this->app->bind(
            SupplierInvoiceRepositoryInterface::class,
            EloquentSupplierInvoiceRepository::class,
        );

        $this->app->bind(
            InvoiceInboxRepositoryInterface::class,
            EloquentInvoiceInboxRepository::class,
        );

        $this->app->bind(
            InvoiceTextExtractor::class,
            CompositeExtractor::class,
        );

        $this->app->bind(
            QuoteRepositoryInterface::class,
            EloquentQuoteRepository::class,
        );

        $this->app->bind(
            PaymentOrderRepositoryInterface::class,
            EloquentPaymentOrderRepository::class,
        );
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../../Presentation/Routes/invoicing.php');

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

        // Unauthenticated document pages (invoice or quote) are looked up by
        // token alone, so they're throttled per IP rather than per account.
        RateLimiter::for('public-doc', function (Request $request): Limit {
            return Limit::perMinute(30)->by($request->ip());
        });

        // Quote accept/reject is a one-shot decision but still worth capping
        // tighter than read-only document views, per IP.
        RateLimiter::for('public-decide', function (Request $request): Limit {
            return Limit::perMinute(10)->by($request->ip());
        });

        Gate::policy(Invoice::class, InvoicePolicy::class);
        Gate::policy(BankAccount::class, BankAccountPolicy::class);
        Gate::policy(RecurringInvoiceTemplate::class, RecurringInvoiceTemplatePolicy::class);
        Gate::policy(SupplierInvoice::class, SupplierInvoicePolicy::class);
        Gate::policy(InvoiceInboxItem::class, InvoiceInboxItemPolicy::class);
        Gate::policy(VatRate::class, VatRatePolicy::class);
        Gate::policy(Quote::class, QuotePolicy::class);
        Gate::policy(PaymentOrder::class, PaymentOrderPolicy::class);

        Event::listen(UserRegistered::class, SeedVatRatesForNewUser::class);
        Event::listen(QuoteAccepted::class, SendQuoteDecisionNotification::class);
        Event::listen(QuoteRejected::class, SendQuoteDecisionNotification::class);

        if ($this->app->runningInConsole()) {
            $this->commands([GenerateRecurringInvoicesCommand::class, ScanInboxCommand::class, BackfillVatRatesCommand::class, SendAutoRemindersCommand::class]);
        }
    }
}
