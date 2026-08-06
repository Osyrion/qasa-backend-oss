<?php

declare(strict_types=1);

use App\Modules\Auth\Domain\Models\User;
use App\Modules\Shared\Application\DTOs\NotificationPayload;
use App\Modules\Shared\Application\Notifications\InAppNotification;
use App\Modules\Shared\Domain\Models\AccountNotification;
use App\Modules\Shared\Enums\NotificationCategory;
use App\Modules\Shared\Enums\NotificationSeverity;

/**
 * Own class/helper names (not the ones in NotificationCenterTest.php) since
 * Pest loads every test file into one global namespace and a duplicate
 * `TestNotification`/`notify()` declaration would be a fatal redeclaration.
 */
final class PreferenceProbeNotification extends InAppNotification
{
    public function __construct(
        private readonly NotificationCategory $category,
    ) {}

    public function payload(object $notifiable): NotificationPayload
    {
        return new NotificationPayload(
            category: $this->category,
            severity: NotificationSeverity::Info,
            title: 'Title',
            body: 'Body',
        );
    }
}

function sendPreferenceProbe(User $user, NotificationCategory $category): void
{
    $user->notify(new PreferenceProbeNotification($category));
}

it('defaults every notification category to enabled', function (): void {
    $owner = createUser();

    $this->actingAs($owner)
        ->getJson('/api/v1/notifications/preferences')
        ->assertOk()
        ->assertJson([
            'notify_invoice_enabled' => true,
            'notify_quote_enabled' => true,
            'notify_tax_enabled' => true,
            'notify_billing_enabled' => true,
            'notify_banking_enabled' => true,
            'notify_system_enabled' => true,
        ]);
});

it('suppresses the in-app row for a disabled category but not others', function (): void {
    $owner = createUser();

    $this->actingAs($owner)
        ->putJson('/api/v1/notifications/preferences', ['notify_tax_enabled' => false])
        ->assertOk()
        ->assertJson(['notify_tax_enabled' => false, 'notify_invoice_enabled' => true]);

    sendPreferenceProbe($owner->refresh(), NotificationCategory::Tax);
    sendPreferenceProbe($owner->refresh(), NotificationCategory::Invoice);

    $categories = AccountNotification::query()
        ->forRecipient($owner->id)
        ->pluck('data')
        ->map(fn (array $data): string => $data['category'])
        ->all();

    expect($categories)->toBe(['invoice']);
});

it('leaves omitted fields unchanged on partial update', function (): void {
    $owner = createUser();

    $this->actingAs($owner)->putJson('/api/v1/notifications/preferences', ['notify_billing_enabled' => false])->assertOk();
    $this->actingAs($owner)
        ->putJson('/api/v1/notifications/preferences', ['notify_invoice_enabled' => false])
        ->assertOk()
        ->assertJson(['notify_billing_enabled' => false, 'notify_invoice_enabled' => false]);
});

it('keeps preferences private per team member', function (): void {
    $owner = createUser();
    $stranger = createUser();

    $this->actingAs($owner)->putJson('/api/v1/notifications/preferences', ['notify_system_enabled' => false])->assertOk();

    $this->actingAs($stranger)
        ->getJson('/api/v1/notifications/preferences')
        ->assertOk()
        ->assertJson(['notify_system_enabled' => true]);
});
