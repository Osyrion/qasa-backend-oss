<?php

declare(strict_types=1);

use App\Modules\Auth\Domain\Models\User;
use App\Modules\Clients\Domain\Models\Client;
use App\Modules\Invoicing\Application\Mail\OverdueInvoicesDigestMail;
use App\Modules\Invoicing\Domain\Models\Invoice;
use App\Modules\Shared\Application\Actions\PurgeNotificationsAction;
use App\Modules\Shared\Application\DTOs\NotificationPayload;
use App\Modules\Shared\Application\Notifications\InAppNotification;
use App\Modules\Shared\Domain\Models\AccountNotification;
use App\Modules\Shared\Enums\NotificationCategory;
use App\Modules\Shared\Enums\NotificationSeverity;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/**
 * A throwaway notification, so these tests exercise the channel and the API
 * rather than any one module's wording.
 *
 * The team-member cases — whose notification an account row belongs to, and
 * who may read it — live in tests/Feature/Team/NotificationRecipientTest.php,
 * which the OSS build deletes along with the module.
 */
final class TestNotification extends InAppNotification
{
    public function __construct(
        private readonly NotificationCategory $category = NotificationCategory::System,
    ) {}

    public function payload(object $notifiable): NotificationPayload
    {
        return new NotificationPayload(
            category: $this->category,
            severity: NotificationSeverity::Info,
            title: 'Title',
            body: 'Body',
            actionUrl: '/somewhere',
        );
    }
}

function notify(User $user, ?NotificationCategory $category = null): void
{
    $user->notify(new TestNotification($category ?? NotificationCategory::System));
}

it('hides one account notifications from another in raw SQL', function (): void {
    $owner = createUser();
    notify($owner);

    $stranger = createUser();

    expect(asAccount($stranger, fn (): array => DB::table('notifications')->pluck('user_id')->all()))
        ->not->toContain($owner->id)
        ->and(asAccount($owner, fn (): array => DB::table('notifications')->pluck('user_id')->all()))
        ->toContain($owner->id);
});

it('counts only the callers unread notifications', function (): void {
    $owner = createUser();

    notify($owner);
    notify($owner);

    $first = AccountNotification::query()->forRecipient($owner->id)->firstOrFail();

    $this->actingAs($owner)->patchJson("/api/v1/notifications/{$first->id}/read")->assertOk();

    $this->actingAs($owner)
        ->getJson('/api/v1/notifications/unread-count')
        ->assertOk()
        ->assertJson(['count' => 1]);
});

it('keeps the original read_at when a notification is marked read twice', function (): void {
    $owner = createUser();
    notify($owner);

    $notification = AccountNotification::query()->forRecipient($owner->id)->firstOrFail();

    $this->actingAs($owner)->patchJson("/api/v1/notifications/{$notification->id}/read")->assertOk();
    $first = $notification->fresh()?->read_at?->toISOString();

    CarbonImmutable::setTestNow(now()->addHour());
    $this->actingAs($owner)->patchJson("/api/v1/notifications/{$notification->id}/read")->assertOk();

    expect($notification->fresh()?->read_at?->toISOString())->toBe($first);

    CarbonImmutable::setTestNow();
});

it('marks every unread notification of the caller as read', function (): void {
    $owner = createUser();

    notify($owner);
    notify($owner);

    $this->actingAs($owner)
        ->postJson('/api/v1/notifications/read-all')
        ->assertOk()
        ->assertJson(['marked' => 2]);

    expect(AccountNotification::query()->forRecipient($owner->id)->whereNull('read_at')->count())->toBe(0);
});

it('filters by category', function (): void {
    $owner = createUser();

    notify($owner, NotificationCategory::Invoice);
    notify($owner, NotificationCategory::Billing);

    $this->actingAs($owner)
        ->getJson('/api/v1/notifications?category=invoice')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.category', 'invoice');
});

it('records an in-app copy of the overdue digest next to the e-mail', function (): void {
    Mail::fake();

    $owner = createUser();
    $client = Client::factory()->create(['user_id' => $owner->id]);
    Invoice::factory()->overdue()->create(['user_id' => $owner->id, 'client_id' => $client->id]);

    $this->artisan('qasa:invoices:overdue-digest')->assertSuccessful();

    // Both channels, dispatched independently: the mail is still assertable
    // as itself, and the notification centre has its own row.
    Mail::assertQueued(OverdueInvoicesDigestMail::class, 1);

    $notification = AccountNotification::query()->forRecipient($owner->id)->firstOrFail();

    expect($notification->data['category'])->toBe('invoice')
        ->and($notification->data['severity'])->toBe('warning')
        ->and($notification->data['action_url'])->toBe('/invoices?status=overdue');
});

it('purges read notifications past the retention window but never unread ones', function (): void {
    config(['notifications.retention_days' => 30]);

    $owner = createUser();

    notify($owner);
    notify($owner);

    $notifications = AccountNotification::query()->forRecipient($owner->id)->get();

    $old = now()->subDays(60);

    // One old and read, one old and unread — only the first is eligible.
    $notifications[0]?->forceFill(['read_at' => $old, 'created_at' => $old])->save();
    $notifications[1]?->forceFill(['created_at' => $old])->save();

    $deleted = app(PurgeNotificationsAction::class)->execute(CarbonImmutable::parse('today'));

    expect($deleted)->toBe(1)
        ->and(AccountNotification::query()->forRecipient($owner->id)->count())->toBe(1)
        ->and(AccountNotification::query()->forRecipient($owner->id)->first()?->read_at)->toBeNull();
});
