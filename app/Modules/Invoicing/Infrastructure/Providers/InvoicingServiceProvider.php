<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Infrastructure\Providers;

use App\Modules\Auth\Domain\Events\TaxResidencyCompleted;
use App\Modules\Invoicing\Application\Actions\AddInvoiceItemAction;
use App\Modules\Invoicing\Application\Actions\CreateInvoiceAction;
use App\Modules\Invoicing\Application\Actions\ProcessInboxFileAction;
use App\Modules\Invoicing\Application\Actions\RecordPaymentAction;
use App\Modules\Invoicing\Application\Actions\SendInvoiceEmailAction;
use App\Modules\Invoicing\Application\Actions\SettleProformaAction;
use App\Modules\Invoicing\Application\Actions\UpdateInvoiceStatusAction;
use App\Modules\Invoicing\Application\Actions\UpdateSupplierInvoiceStatusAction;
use App\Modules\Invoicing\Application\Contracts\AddInvoiceItemActionInterface;
use App\Modules\Invoicing\Application\Contracts\AiAssistantServiceInterface;
use App\Modules\Invoicing\Application\Contracts\BankAccountRepositoryInterface;
use App\Modules\Invoicing\Application\Contracts\CnbRateClientInterface;
use App\Modules\Invoicing\Application\Contracts\CreateInvoiceActionInterface;
use App\Modules\Invoicing\Application\Contracts\ExchangeRateServiceInterface;
use App\Modules\Invoicing\Application\Contracts\ExpenseRepositoryInterface;
use App\Modules\Invoicing\Application\Contracts\InvoiceInboxRepositoryInterface;
use App\Modules\Invoicing\Application\Contracts\InvoiceRepositoryInterface;
use App\Modules\Invoicing\Application\Contracts\ProcessInboxFileActionInterface;
use App\Modules\Invoicing\Application\Contracts\QuoteRepositoryInterface;
use App\Modules\Invoicing\Application\Contracts\RecordPaymentActionInterface;
use App\Modules\Invoicing\Application\Contracts\RecurringInvoiceTemplateRepositoryInterface;
use App\Modules\Invoicing\Application\Contracts\SendInvoiceEmailActionInterface;
use App\Modules\Invoicing\Application\Contracts\SettleProformaActionInterface;
use App\Modules\Invoicing\Application\Contracts\SupplierInvoiceRepositoryInterface;
use App\Modules\Invoicing\Application\Contracts\TrackedWorkLinkInterface;
use App\Modules\Invoicing\Application\Contracts\UpdateInvoiceStatusActionInterface;
use App\Modules\Invoicing\Application\Contracts\UpdateSupplierInvoiceStatusActionInterface;
use App\Modules\Invoicing\Application\Contracts\UsageQuotaInterface;
use App\Modules\Invoicing\Application\Contracts\VatRateRepositoryInterface;
use App\Modules\Invoicing\Application\Contracts\WorkReportGeneratorInterface;
use App\Modules\Invoicing\Application\Listeners\SeedVatRatesForNewUser;
use App\Modules\Invoicing\Application\Listeners\SendQuoteDecisionNotification;
use App\Modules\Invoicing\Application\Services\AiAssistantService;
use App\Modules\Invoicing\Application\Services\ExchangeRateService;
use App\Modules\Invoicing\Application\Services\LlmProviderRegistry;
use App\Modules\Invoicing\Application\Services\NoTrackedWorkLink;
use App\Modules\Invoicing\Application\Services\NoWorkReportGenerator;
use App\Modules\Invoicing\Domain\Banking\EpcQrBuilder;
use App\Modules\Invoicing\Domain\Banking\PayBySquareBuilder;
use App\Modules\Invoicing\Domain\Banking\PaymentSchemeRegistry;
use App\Modules\Invoicing\Domain\Banking\SpaydBuilder;
use App\Modules\Invoicing\Domain\Contracts\InvoiceFieldExtractor;
use App\Modules\Invoicing\Domain\Contracts\InvoiceTextExtractor;
use App\Modules\Invoicing\Domain\Contracts\OnlinePaymentAvailabilityInterface;
use App\Modules\Invoicing\Domain\Events\QuoteAccepted;
use App\Modules\Invoicing\Domain\Events\QuoteRejected;
use App\Modules\Invoicing\Domain\Models\BankAccount;
use App\Modules\Invoicing\Domain\Models\CashDocument;
use App\Modules\Invoicing\Domain\Models\ExchangeRate;
use App\Modules\Invoicing\Domain\Models\Expense;
use App\Modules\Invoicing\Domain\Models\Invoice;
use App\Modules\Invoicing\Domain\Models\InvoiceInboxItem;
use App\Modules\Invoicing\Domain\Models\Quote;
use App\Modules\Invoicing\Domain\Models\RecurringInvoiceTemplate;
use App\Modules\Invoicing\Domain\Models\SupplierInvoice;
use App\Modules\Invoicing\Domain\Models\VatRate;
use App\Modules\Invoicing\Infrastructure\Clients\CnbApiRateClient;
use App\Modules\Invoicing\Infrastructure\Ocr\CompositeExtractor;
use App\Modules\Invoicing\Infrastructure\Ocr\Providers\AnthropicDriver;
use App\Modules\Invoicing\Infrastructure\Ocr\RegexFieldExtractor;
use App\Modules\Invoicing\Infrastructure\Ocr\UnlimitedUsageQuota;
use App\Modules\Invoicing\Infrastructure\Payments\AlwaysUnavailableOnlinePayment;
use App\Modules\Invoicing\Infrastructure\Repositories\EloquentBankAccountRepository;
use App\Modules\Invoicing\Infrastructure\Repositories\EloquentExpenseRepository;
use App\Modules\Invoicing\Infrastructure\Repositories\EloquentInvoiceInboxRepository;
use App\Modules\Invoicing\Infrastructure\Repositories\EloquentInvoiceRepository;
use App\Modules\Invoicing\Infrastructure\Repositories\EloquentQuoteRepository;
use App\Modules\Invoicing\Infrastructure\Repositories\EloquentRecurringInvoiceTemplateRepository;
use App\Modules\Invoicing\Infrastructure\Repositories\EloquentSupplierInvoiceRepository;
use App\Modules\Invoicing\Infrastructure\Repositories\EloquentVatRateRepository;
use App\Modules\Invoicing\Presentation\Console\BackfillVatRatesCommand;
use App\Modules\Invoicing\Presentation\Console\ScanInboxCommand;
use App\Modules\Invoicing\Presentation\Console\SendOverdueDigestCommand;
use App\Modules\Invoicing\Presentation\Policies\BankAccountPolicy;
use App\Modules\Invoicing\Presentation\Policies\CashDocumentPolicy;
use App\Modules\Invoicing\Presentation\Policies\ExchangeRatePolicy;
use App\Modules\Invoicing\Presentation\Policies\ExpensePolicy;
use App\Modules\Invoicing\Presentation\Policies\InvoiceInboxItemPolicy;
use App\Modules\Invoicing\Presentation\Policies\InvoicePolicy;
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
            VatRateRepositoryInterface::class,
            EloquentVatRateRepository::class,
        );

        $this->app->bind(
            QuoteRepositoryInterface::class,
            EloquentQuoteRepository::class,
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

        // Default/fallback driver — FieldExtractorFactory resolves the
        // actual per-item choice (regex vs LLM); this binding is what the
        // fallback path and any direct injection get.
        $this->app->bind(
            InvoiceFieldExtractor::class,
            RegexFieldExtractor::class,
        );

        // OSS core default (unlimited); SaasServiceProvider overrides this
        // with the real subscription_usages-backed UsageService.
        $this->app->bind(
            UsageQuotaInterface::class,
            UnlimitedUsageQuota::class,
        );

        // Phase 3 Part C — premium modules (Reports, Automation) depend on
        // this contract, never the concrete AiAssistantService, per
        // ModuleBoundariesTest. One implementation regardless of edition
        // (unlike UsageQuotaInterface above): AiAssistantService already
        // degrades to "unavailable" on its own when ai_assistant isn't on
        // the plan, so OSS/no-plan accounts don't need a different binding.
        $this->app->bind(
            AiAssistantServiceInterface::class,
            AiAssistantService::class,
        );

        // OSS core default (button never shows); IntegrationsServiceProvider
        // overrides this against the owner's Stripe Connect account.
        $this->app->bind(
            OnlinePaymentAvailabilityInterface::class,
            AlwaysUnavailableOnlinePayment::class,
        );

        $this->app->bind(
            ExpenseRepositoryInterface::class,
            EloquentExpenseRepository::class,
        );

        $this->app->bind(
            RecordPaymentActionInterface::class,
            RecordPaymentAction::class,
        );

        $this->app->bind(
            CreateInvoiceActionInterface::class,
            CreateInvoiceAction::class,
        );

        $this->app->bind(
            AddInvoiceItemActionInterface::class,
            AddInvoiceItemAction::class,
        );

        $this->app->bind(
            UpdateInvoiceStatusActionInterface::class,
            UpdateInvoiceStatusAction::class,
        );

        $this->app->bind(
            ProcessInboxFileActionInterface::class,
            ProcessInboxFileAction::class,
        );

        $this->app->bind(
            SettleProformaActionInterface::class,
            SettleProformaAction::class,
        );

        $this->app->bind(
            SendInvoiceEmailActionInterface::class,
            SendInvoiceEmailAction::class,
        );

        $this->app->bind(
            UpdateSupplierInvoiceStatusActionInterface::class,
            UpdateSupplierInvoiceStatusAction::class,
        );

        $this->app->bind(
            CnbRateClientInterface::class,
            CnbApiRateClient::class,
        );

        $this->app->bind(
            ExchangeRateServiceInterface::class,
            ExchangeRateService::class,
        );

        // OSS core defaults (nothing tracks work); TimeTrackingServiceProvider
        // rebinds both to their TimeEntry-backed implementations.
        $this->app->bind(
            WorkReportGeneratorInterface::class,
            NoWorkReportGenerator::class,
        );

        $this->app->bind(
            TrackedWorkLinkInterface::class,
            NoTrackedWorkLink::class,
        );

        // One binding per LlmProviderDriver implementation — adding a
        // provider is a new driver class plus one line here.
        $this->app->tag([AnthropicDriver::class], 'llm.providers');

        $this->app->singleton(
            LlmProviderRegistry::class,
            fn ($app): LlmProviderRegistry => new LlmProviderRegistry($app->tagged('llm.providers')),
        );

        // Priority order — see PaymentSchemeRegistry's docblock.
        $this->app->singleton(PaymentSchemeRegistry::class, fn ($app): PaymentSchemeRegistry => new PaymentSchemeRegistry([
            $app->make(PayBySquareBuilder::class),
            $app->make(SpaydBuilder::class),
            $app->make(EpcQrBuilder::class),
        ]));
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

        // Costs a (tiny) real provider API call per attempt.
        RateLimiter::for('ai-credential-test', function (Request $request): Limit {
            return Limit::perMinute(5)->by(
                (string) ($request->user()?->getAuthIdentifier() ?? $request->ip()),
            );
        });

        // Public quote page (read + PDF): looser, per-IP — a client may
        // reload or re-download several times while reviewing an offer.
        RateLimiter::for('public-doc', function (Request $request): Limit {
            return Limit::perMinute(30)->by($request->ip());
        });

        // Public accept/reject: one-shot and definitive, so a tight per-IP
        // cap is enough to blunt brute-force token guessing without
        // affecting a legitimate single decision.
        RateLimiter::for('public-decide', function (Request $request): Limit {
            return Limit::perMinute(10)->by($request->ip());
        });

        // Client portal: a durable link a client keeps and revisits, so the
        // budget sits between public-doc (one document, occasional reload)
        // and an authenticated session.
        RateLimiter::for('client-portal', function (Request $request): Limit {
            return Limit::perMinute(60)->by($request->ip());
        });

        Gate::policy(CashDocument::class, CashDocumentPolicy::class);
        Gate::policy(Invoice::class, InvoicePolicy::class);
        Gate::policy(BankAccount::class, BankAccountPolicy::class);
        Gate::policy(RecurringInvoiceTemplate::class, RecurringInvoiceTemplatePolicy::class);
        Gate::policy(SupplierInvoice::class, SupplierInvoicePolicy::class);
        Gate::policy(InvoiceInboxItem::class, InvoiceInboxItemPolicy::class);
        Gate::policy(VatRate::class, VatRatePolicy::class);
        Gate::policy(Quote::class, QuotePolicy::class);
        Gate::policy(Expense::class, ExpensePolicy::class);
        Gate::policy(ExchangeRate::class, ExchangeRatePolicy::class);

        Event::listen(TaxResidencyCompleted::class, SeedVatRatesForNewUser::class);
        Event::listen(QuoteAccepted::class, SendQuoteDecisionNotification::class);
        Event::listen(QuoteRejected::class, SendQuoteDecisionNotification::class);

        if ($this->app->runningInConsole()) {
            $this->commands([
                ScanInboxCommand::class,
                SendOverdueDigestCommand::class,
                BackfillVatRatesCommand::class,
            ]);
        }
    }
}
