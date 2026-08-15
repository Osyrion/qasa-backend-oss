<?php

declare(strict_types=1);

namespace App\Modules\Shared\Infrastructure\Providers;

use App\Modules\Auth\Domain\Events\AccountDeleted;
use App\Modules\Auth\Domain\Events\UserIcoChanged;
use App\Modules\Auth\Domain\Events\UserRegistered;
use App\Modules\Auth\Domain\Models\User;
use App\Modules\Clients\Domain\Events\ClientCreated;
use App\Modules\Clients\Domain\Events\ClientDeleted;
use App\Modules\Clients\Domain\Events\ClientUpdated;
use App\Modules\Clients\Domain\Models\Client;
use App\Modules\Invoicing\Domain\Events\CashDocumentReversed;
use App\Modules\Invoicing\Domain\Events\InvoiceCreated;
use App\Modules\Invoicing\Domain\Events\InvoiceIssued;
use App\Modules\Invoicing\Domain\Events\InvoicePaid;
use App\Modules\Invoicing\Domain\Events\InvoiceReminded;
use App\Modules\Invoicing\Domain\Events\InvoiceSent;
use App\Modules\Invoicing\Domain\Events\PaymentDeleted;
use App\Modules\Invoicing\Domain\Events\PaymentRecorded;
use App\Modules\Invoicing\Domain\Events\QuoteAccepted;
use App\Modules\Invoicing\Domain\Events\QuoteRejected;
use App\Modules\Invoicing\Domain\Models\CashDocument;
use App\Modules\Invoicing\Domain\Models\Invoice;
use App\Modules\Invoicing\Domain\Models\Quote;
use App\Modules\Orders\Domain\Events\OrderCreated;
use App\Modules\Orders\Domain\Events\OrderDeleted;
use App\Modules\Orders\Domain\Events\OrderUpdated;
use App\Modules\Orders\Domain\Models\Order;
use App\Modules\Shared\Application\Actions\PurgeRequestMetadataAction;
use App\Modules\Shared\Application\Contracts\ActivityRecorderInterface;
use App\Modules\Shared\Application\Contracts\AiModelResolver;
use App\Modules\Shared\Application\Services\ActivityEventRegistry;
use App\Modules\Shared\Application\Services\AiTransparencyRegister;
use App\Modules\Shared\Application\Services\NullAiModelResolver;
use App\Modules\Shared\Domain\Models\AccountNotification;
use App\Modules\Shared\Infrastructure\Notifications\AccountDatabaseChannel;
use App\Modules\Shared\Infrastructure\Repositories\EloquentActivityRecorder;
use App\Modules\Shared\Infrastructure\Sentry\SentryContext;
use App\Modules\Shared\Policies\NotificationPolicy;
use App\Modules\Shared\Presentation\Console\PurgeActivityLogCommand;
use App\Modules\Shared\Presentation\Console\PurgeIdempotencyKeysCommand;
use App\Modules\Shared\Presentation\Console\PurgeNotificationsCommand;
use App\Modules\Shared\Presentation\Console\PurgeRequestMetadataCommand;
use App\Modules\Shared\Presentation\Console\VerifyActivityChainCommand;
use App\Modules\Shared\Presentation\Console\VerifyRowLevelSecurityCommand;
use App\Modules\Shared\Support\OwnerConnection;
use App\Modules\Shared\Support\TenantContext;
use App\Modules\Shared\Support\TenantQueue;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Events\ConnectionEstablished;
use Illuminate\Notifications\ChannelManager;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

class SharedServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(ActivityRecorderInterface::class, EloquentActivityRecorder::class);
        $this->app->singleton(ActivityEventRegistry::class);

        // Tables owned by other modules clear their own metadata — the tag is
        // empty in the OSS edition, which is how the admin audit's entry
        // disappears there along with the module that owns it.
        $this->app->bind(
            PurgeRequestMetadataAction::class,
            fn ($app): PurgeRequestMetadataAction => new PurgeRequestMetadataAction($app->tagged('privacy.purge')),
        );

        // bindIf, not bind: this provider is registered *last* (providers.php
        // is alphabetical), so a plain bind would overwrite Invoicing's real
        // resolver with the no-op instead of the other way round — see
        // AiModelResolver.
        $this->app->bindIf(AiModelResolver::class, NullAiModelResolver::class);

        // The AI capabilities each module declares for the art. 50
        // transparency notice; an edition with fewer modules simply has
        // fewer of them (see AiFeatureDescriptor).
        $this->app->bind(
            AiTransparencyRegister::class,
            fn ($app): AiTransparencyRegister => new AiTransparencyRegister(
                $app->tagged('ai.features'),
                $app->make(AiModelResolver::class),
            ),
        );
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../../Presentation/Routes/activity.php');
        $this->loadRoutesFrom(__DIR__.'/../../Presentation/Routes/ai.php');
        $this->loadRoutesFrom(__DIR__.'/../../Presentation/Routes/health.php');
        $this->loadRoutesFrom(__DIR__.'/../../Presentation/Routes/notifications.php');

        $this->bindTenantContext();
        $this->bindBackgroundCorrelation();
        $this->registerNotificationChannel();

        Gate::policy(AccountNotification::class, NotificationPolicy::class);

        if ($this->app->runningInConsole()) {
            OwnerConnection::routeSchemaCommands();
        }

        // Non-enforcing: only aliases these models for readable subject_type
        // values. enforceMorphMap() would require every polymorphic relation
        // app-wide (Sanctum's tokenable, notifications' notifiable, ...) to
        // be registered here too.
        Relation::morphMap([
            'client' => Client::class,
            'order' => Order::class,
            'invoice' => Invoice::class,
            'quote' => Quote::class,
            'user' => User::class,
        ]);

        $this->registerActivityEvents();

        if ($this->app->runningInConsole()) {
            $this->commands([
                PurgeActivityLogCommand::class,
                PurgeIdempotencyKeysCommand::class,
                PurgeNotificationsCommand::class,
                PurgeRequestMetadataCommand::class,
                VerifyActivityChainCommand::class,
                VerifyRowLevelSecurityCommand::class,
            ]);
        }
    }

    /**
     * Replace the framework's `database` channel rather than adding a new
     * name, so a notification's via() saying 'database' keeps meaning what
     * everyone expects — it just also records which account the row belongs
     * to, which the RLS policy requires.
     */
    private function registerNotificationChannel(): void
    {
        Notification::resolved(function (ChannelManager $manager): void {
            $manager->extend('database', fn (): AccountDatabaseChannel => new AccountDatabaseChannel);
        });
    }

    /**
     * Activity-log events owned by the OSS modules. Premium modules register
     * their own from their own providers — see ActivityEventRegistry.
     */
    private function registerActivityEvents(): void
    {
        $registry = $this->app->make(ActivityEventRegistry::class);

        $registry->register(ClientCreated::class, 'client.created', fn (ClientCreated $e): Client => $e->client);
        $registry->register(ClientUpdated::class, 'client.updated', fn (ClientUpdated $e): Client => $e->client);
        $registry->register(ClientDeleted::class, 'client.deleted', fn (ClientDeleted $e): Client => $e->client);

        $registry->register(OrderCreated::class, 'order.created', fn (OrderCreated $e): Order => $e->order);
        $registry->register(OrderUpdated::class, 'order.updated', fn (OrderUpdated $e): Order => $e->order);
        $registry->register(OrderDeleted::class, 'order.deleted', fn (OrderDeleted $e): Order => $e->order);

        $registry->register(InvoiceCreated::class, 'invoice.created', fn (InvoiceCreated $e): Invoice => $e->invoice);
        $registry->register(InvoiceIssued::class, 'invoice.issued', fn (InvoiceIssued $e): Invoice => $e->invoice);
        $registry->register(InvoiceSent::class, 'invoice.sent', fn (InvoiceSent $e): Invoice => $e->invoice);
        $registry->register(InvoicePaid::class, 'invoice.paid', fn (InvoicePaid $e): Invoice => $e->invoice);
        $registry->register(InvoiceReminded::class, 'invoice.reminded', fn (InvoiceReminded $e): Invoice => $e->invoice);

        // The payment itself has no user_id — it's owned through its invoice,
        // which is also the more natural subject of "money was recorded
        // against this invoice" in an audit feed.
        $registry->register(
            PaymentRecorded::class,
            'payment.recorded',
            fn (PaymentRecorded $e): Invoice => $e->invoice,
            fn (PaymentRecorded $e): array => [
                'payment_id' => $e->payment->id,
                'amount' => (string) $e->payment->amount,
            ],
        );

        // Same non-user_id shape as PaymentRecorded above — the payment is
        // gone by the time anything downstream would look at it, so the
        // invoice is both the natural subject and the only one that still
        // exists to carry a user_id.
        $registry->register(
            PaymentDeleted::class,
            'payment.deleted',
            fn (PaymentDeleted $e): Invoice => $e->invoice,
            fn (PaymentDeleted $e): array => [
                'payment_id' => $e->payment->id,
                'amount' => (string) $e->payment->amount,
            ],
        );

        // Subject is the original, not the reversal — "this document was
        // reversed" reads more naturally against the one someone is looking
        // at than against its mirror image.
        $registry->register(
            CashDocumentReversed::class,
            'cash_document.reversed',
            fn (CashDocumentReversed $e): CashDocument => $e->original,
            fn (CashDocumentReversed $e): array => [
                'amount' => (string) $e->original->amount,
                'currency' => $e->original->currency->value,
                'reversal_number' => $e->reversal->number,
            ],
        );

        $registry->register(QuoteAccepted::class, 'quote.accepted', fn (QuoteAccepted $e): Quote => $e->quote);
        $registry->register(QuoteRejected::class, 'quote.rejected', fn (QuoteRejected $e): Quote => $e->quote);

        $registry->register(UserRegistered::class, 'user.registered', fn (UserRegistered $e): User => $e->user);
        $registry->register(AccountDeleted::class, 'account.deleted', fn (AccountDeleted $e): User => $e->user);
        $registry->register(
            UserIcoChanged::class,
            'user.ico_changed',
            fn (UserIcoChanged $e): User => $e->user,
            fn (UserIcoChanged $e): array => [
                'old_ico' => $e->oldIco,
                'new_ico' => $e->newIco,
            ],
        );
    }

    /**
     * Everything that binds the connection to an account outside a request.
     *
     * Requests are bound by the auth middleware, which is the only place that
     * knows authentication succeeded — see Middleware\Authenticate. Jobs
     * carry the account in their payload, because a worker has neither.
     */
    /**
     * A correlation id for work that has no request behind it.
     *
     * Log::withContext was set in exactly two places before this — the
     * RequestId middleware and one job that carries its dispatching request's
     * id forward — so every scheduled command and every other job wrote log
     * lines with nothing tying them together, and would have arrived in
     * Sentry the same way. One listener each beats repeating it in 27
     * commands and 5 jobs, and the job's own uuid is used where there is one
     * so the id matches what the failed_jobs row holds.
     */
    private function bindBackgroundCorrelation(): void
    {
        Event::listen(function (CommandStarting $event): void {
            $command = $event->command ?? 'artisan';
            $runId = (string) Str::uuid();

            Log::withContext(['run_id' => $runId, 'command' => $command]);
            SentryContext::tagBackgroundRun('command', $command, $runId);
        });

        Event::listen(function (JobProcessing $event): void {
            $job = $event->job->resolveName();
            $runId = $event->job->uuid() ?? (string) Str::uuid();

            Log::withContext(['run_id' => $runId, 'job' => $job]);
            SentryContext::tagBackgroundRun('job', $job, $runId);
        });
    }

    private function bindTenantContext(): void
    {
        TenantQueue::listen();

        Event::listen(function (ConnectionEstablished $event): void {
            if ($event->connectionName === config('database.default')) {
                TenantContext::restoreAfterReconnect();
            }
        });
    }
}
