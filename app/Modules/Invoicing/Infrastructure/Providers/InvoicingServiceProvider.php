<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Infrastructure\Providers;

use App\Modules\Auth\Domain\Events\TaxResidencyCompleted;
use App\Modules\Invoicing\Application\Actions\AddInvoiceItemAction;
use App\Modules\Invoicing\Application\Actions\CreateInvoiceAction;
use App\Modules\Invoicing\Application\Actions\GenerateInvoiceFromTemplateAction;
use App\Modules\Invoicing\Application\Actions\ProcessInboxFileAction;
use App\Modules\Invoicing\Application\Actions\RecordPaymentAction;
use App\Modules\Invoicing\Application\Actions\SendInvoiceEmailAction;
use App\Modules\Invoicing\Application\Actions\SettleProformaAction;
use App\Modules\Invoicing\Application\Actions\UpdateInvoiceStatusAction;
use App\Modules\Invoicing\Application\Actions\UpdateSupplierInvoiceStatusAction;
use App\Modules\Invoicing\Application\Contracts\AccountInvoicingState;
use App\Modules\Invoicing\Application\Contracts\AddInvoiceItemActionInterface;
use App\Modules\Invoicing\Application\Contracts\AiAssistantServiceInterface;
use App\Modules\Invoicing\Application\Contracts\AutomationAnalytics;
use App\Modules\Invoicing\Application\Contracts\BankAccountLookup;
use App\Modules\Invoicing\Application\Contracts\BankAccountRepositoryInterface;
use App\Modules\Invoicing\Application\Contracts\ByokCredentialResolverInterface;
use App\Modules\Invoicing\Application\Contracts\CashBasisAnalytics;
use App\Modules\Invoicing\Application\Contracts\CnbRateClientInterface;
use App\Modules\Invoicing\Application\Contracts\CreateInvoiceActionInterface;
use App\Modules\Invoicing\Application\Contracts\EuSalesListSourceInterface;
use App\Modules\Invoicing\Application\Contracts\ExchangeRateServiceInterface;
use App\Modules\Invoicing\Application\Contracts\ExpenseAuthorization;
use App\Modules\Invoicing\Application\Contracts\ExpenseHistory;
use App\Modules\Invoicing\Application\Contracts\ExpenseRepositoryInterface;
use App\Modules\Invoicing\Application\Contracts\GenerateInvoiceFromTemplateActionInterface;
use App\Modules\Invoicing\Application\Contracts\InvoiceAuthorization;
use App\Modules\Invoicing\Application\Contracts\InvoiceImportRegistry;
use App\Modules\Invoicing\Application\Contracts\InvoiceInboxRepositoryInterface;
use App\Modules\Invoicing\Application\Contracts\InvoiceLookup;
use App\Modules\Invoicing\Application\Contracts\InvoiceReminderRunner;
use App\Modules\Invoicing\Application\Contracts\InvoiceRepositoryInterface;
use App\Modules\Invoicing\Application\Contracts\InvoiceRepresentation;
use App\Modules\Invoicing\Application\Contracts\InvoicingWorkQueue;
use App\Modules\Invoicing\Application\Contracts\LlmFieldExtractorFactoryInterface;
use App\Modules\Invoicing\Application\Contracts\ProcessInboxFileActionInterface;
use App\Modules\Invoicing\Application\Contracts\PublicInvoiceLookup;
use App\Modules\Invoicing\Application\Contracts\QuoteRepositoryInterface;
use App\Modules\Invoicing\Application\Contracts\ReceivablesAnalytics;
use App\Modules\Invoicing\Application\Contracts\RecordPaymentActionInterface;
use App\Modules\Invoicing\Application\Contracts\RecurringInvoiceAnalytics;
use App\Modules\Invoicing\Application\Contracts\RecurringInvoiceTemplateRepositoryInterface;
use App\Modules\Invoicing\Application\Contracts\RenderableDocuments;
use App\Modules\Invoicing\Application\Contracts\SendInvoiceEmailActionInterface;
use App\Modules\Invoicing\Application\Contracts\SettlementAnalytics;
use App\Modules\Invoicing\Application\Contracts\SettleProformaActionInterface;
use App\Modules\Invoicing\Application\Contracts\SupplierInvoiceAuthorization;
use App\Modules\Invoicing\Application\Contracts\SupplierInvoicePayments;
use App\Modules\Invoicing\Application\Contracts\SupplierInvoiceRepositoryInterface;
use App\Modules\Invoicing\Application\Contracts\TrackedWorkDates;
use App\Modules\Invoicing\Application\Contracts\TrackedWorkLinkInterface;
use App\Modules\Invoicing\Application\Contracts\TurnoverAnalytics;
use App\Modules\Invoicing\Application\Contracts\UblInvoiceBuilderInterface;
use App\Modules\Invoicing\Application\Contracts\UblInvoiceParserInterface;
use App\Modules\Invoicing\Application\Contracts\UpdateInvoiceStatusActionInterface;
use App\Modules\Invoicing\Application\Contracts\UpdateSupplierInvoiceStatusActionInterface;
use App\Modules\Invoicing\Application\Contracts\UsageQuotaInterface;
use App\Modules\Invoicing\Application\Contracts\VatControlStatementSourceInterface;
use App\Modules\Invoicing\Application\Contracts\VatRateRepositoryInterface;
use App\Modules\Invoicing\Application\Contracts\VatRecapAnalytics;
use App\Modules\Invoicing\Application\Contracts\VatReturnAggregatorInterface;
use App\Modules\Invoicing\Application\Listeners\SeedVatRatesForNewUser;
use App\Modules\Invoicing\Application\Listeners\SendQuoteDecisionNotification;
use App\Modules\Invoicing\Application\Services\AiAssistantService;
use App\Modules\Invoicing\Application\Services\ConfiguredAiModelResolver;
use App\Modules\Invoicing\Application\Services\EloquentAccountInvoicingState;
use App\Modules\Invoicing\Application\Services\EloquentAutomationAnalytics;
use App\Modules\Invoicing\Application\Services\EloquentBankAccountLookup;
use App\Modules\Invoicing\Application\Services\EloquentCashBasisAnalytics;
use App\Modules\Invoicing\Application\Services\EloquentExpenseHistory;
use App\Modules\Invoicing\Application\Services\EloquentInvoiceImportRegistry;
use App\Modules\Invoicing\Application\Services\EloquentInvoiceLookup;
use App\Modules\Invoicing\Application\Services\EloquentInvoicingWorkQueue;
use App\Modules\Invoicing\Application\Services\EloquentPublicInvoiceLookup;
use App\Modules\Invoicing\Application\Services\EloquentReceivablesAnalytics;
use App\Modules\Invoicing\Application\Services\EloquentRecurringInvoiceAnalytics;
use App\Modules\Invoicing\Application\Services\EloquentRenderableDocuments;
use App\Modules\Invoicing\Application\Services\EloquentSettlementAnalytics;
use App\Modules\Invoicing\Application\Services\EloquentSupplierInvoicePayments;
use App\Modules\Invoicing\Application\Services\EloquentTurnoverAnalytics;
use App\Modules\Invoicing\Application\Services\EloquentVatRecapAnalytics;
use App\Modules\Invoicing\Application\Services\EuSalesListService;
use App\Modules\Invoicing\Application\Services\ExchangeRateService;
use App\Modules\Invoicing\Application\Services\GateExpenseAuthorization;
use App\Modules\Invoicing\Application\Services\GateInvoiceAuthorization;
use App\Modules\Invoicing\Application\Services\GateSupplierInvoiceAuthorization;
use App\Modules\Invoicing\Application\Services\InvoiceExtractionAiFeature;
use App\Modules\Invoicing\Application\Services\InvoicingAccountData;
use App\Modules\Invoicing\Application\Services\InvoicingDashboardStats;
use App\Modules\Invoicing\Application\Services\InvoicingLinkableRecords;
use App\Modules\Invoicing\Application\Services\LlmProviderRegistry;
use App\Modules\Invoicing\Application\Services\NoTrackedWorkDates;
use App\Modules\Invoicing\Application\Services\NoTrackedWorkLink;
use App\Modules\Invoicing\Application\Services\OverdueInvoiceReminder;
use App\Modules\Invoicing\Application\Services\VatControlStatementService;
use App\Modules\Invoicing\Application\Services\VatReturnAggregationService;
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
use App\Modules\Invoicing\Infrastructure\Ocr\ByokCredentialResolver;
use App\Modules\Invoicing\Infrastructure\Ocr\CompositeExtractor;
use App\Modules\Invoicing\Infrastructure\Ocr\LlmFieldExtractorFactory;
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
use App\Modules\Invoicing\Infrastructure\Ubl\Ubl21InvoiceBuilder;
use App\Modules\Invoicing\Infrastructure\Ubl\Ubl21InvoiceParser;
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
use App\Modules\Invoicing\Presentation\Support\InvoiceResourceRepresentation;
use App\Modules\Shared\Application\Contracts\AiModelResolver;
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
        $this->app->bind(GenerateInvoiceFromTemplateActionInterface::class, GenerateInvoiceFromTemplateAction::class);
        $this->app->bind(InvoiceLookup::class, EloquentInvoiceLookup::class);
        $this->app->bind(InvoiceAuthorization::class, GateInvoiceAuthorization::class);
        $this->app->bind(UblInvoiceParserInterface::class, Ubl21InvoiceParser::class);
        $this->app->bind(ByokCredentialResolverInterface::class, ByokCredentialResolver::class);
        $this->app->bind(LlmFieldExtractorFactoryInterface::class, LlmFieldExtractorFactory::class);
        $this->app->bind(
            InvoiceRepositoryInterface::class,
            EloquentInvoiceRepository::class,
        );

        // Reached from outside the module by the premium Peppol transport;
        // inside the module the concrete builder is used directly, same
        // convention as ProcessInboxFileAction.
        $this->app->bind(
            UblInvoiceBuilderInterface::class,
            Ubl21InvoiceBuilder::class,
        );

        $this->app->bind(
            BankAccountRepositoryInterface::class,
            EloquentBankAccountRepository::class,
        );

        // The payment side, for Banking: an id in, a value out. The
        // repositories above stay what our own screens use.
        $this->app->bind(BankAccountLookup::class, EloquentBankAccountLookup::class);
        $this->app->bind(SupplierInvoicePayments::class, EloquentSupplierInvoicePayments::class);
        $this->app->bind(SupplierInvoiceAuthorization::class, GateSupplierInvoiceAuthorization::class);
        $this->app->bind(PublicInvoiceLookup::class, EloquentPublicInvoiceLookup::class);
        $this->app->bind(ExpenseHistory::class, EloquentExpenseHistory::class);
        $this->app->bind(ExpenseAuthorization::class, GateExpenseAuthorization::class);
        $this->app->bind(InvoiceRepresentation::class, InvoiceResourceRepresentation::class);
        $this->app->bind(InvoiceImportRegistry::class, EloquentInvoiceImportRegistry::class);

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

        // Taxation's SK/CZ builders assemble the statements; the invoice rows
        // and the totals behind them stay Invoicing's business.
        $this->app->bind(VatControlStatementSourceInterface::class, VatControlStatementService::class);
        $this->app->bind(EuSalesListSourceInterface::class, EuSalesListService::class);
        $this->app->bind(VatReturnAggregatorInterface::class, VatReturnAggregationService::class);

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
            TrackedWorkDates::class,
            NoTrackedWorkDates::class,
        );

        $this->app->bind(
            TrackedWorkLinkInterface::class,
            NoTrackedWorkLink::class,
        );

        // One binding per LlmProviderDriver implementation — adding a
        // provider is a new driver class plus one line here.
        $this->app->tag([AnthropicDriver::class], 'llm.providers');

        // Extraction is the one AI capability that ships in both editions,
        // so its transparency entry (and the real model resolution behind
        // Shared's no-op default) is registered here — see
        // AiFeatureDescriptor / AiModelResolver.
        $this->app->tag([InvoiceExtractionAiFeature::class], ['ai.features']);

        // Invoicing's own sections of the GDPR account export — Auth assembles
        // the payload but must not know what an invoice row looks like.
        $this->app->tag([InvoicingAccountData::class], ['account.export']);
        $this->app->tag([InvoicingDashboardStats::class], ['dashboard.stats']);
        $this->app->tag([InvoicingLinkableRecords::class], ['linkable.records']);

        $this->app->bind(AccountInvoicingState::class, EloquentAccountInvoicingState::class);
        $this->app->bind(InvoiceReminderRunner::class, OverdueInvoiceReminder::class);
        $this->app->bind(AutomationAnalytics::class, EloquentAutomationAnalytics::class);
        $this->app->bind(InvoicingWorkQueue::class, EloquentInvoicingWorkQueue::class);
        $this->app->bind(ReceivablesAnalytics::class, EloquentReceivablesAnalytics::class);
        $this->app->bind(RecurringInvoiceAnalytics::class, EloquentRecurringInvoiceAnalytics::class);
        $this->app->bind(SettlementAnalytics::class, EloquentSettlementAnalytics::class);
        $this->app->bind(TurnoverAnalytics::class, EloquentTurnoverAnalytics::class);
        $this->app->bind(CashBasisAnalytics::class, EloquentCashBasisAnalytics::class);
        $this->app->bind(RenderableDocuments::class, EloquentRenderableDocuments::class);
        $this->app->bind(VatRecapAnalytics::class, EloquentVatRecapAnalytics::class);
        $this->app->bind(AiModelResolver::class, ConfiguredAiModelResolver::class);

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
