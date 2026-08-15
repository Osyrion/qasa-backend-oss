<?php

declare(strict_types=1);

use App\Modules\Auth\Domain\Models\User;
use App\Modules\Clients\Domain\Models\Client;
use App\Modules\Invoicing\Domain\Enums\InvoiceStatus;
use App\Modules\Invoicing\Domain\Models\Invoice;
use App\Modules\Shared\Domain\Models\ActivityLog;
use App\Modules\Shared\Support\AccountLookup;

it('records an activity entry when a client is created', function (): void {
    $user = createUser();

    $this->actingAs($user)->postJson('/api/v1/clients', [
        'client_type' => 'company',
        'company_name' => 'ACME s.r.o.',
        'is_vat_payer' => false,
        'country' => 'SK',
        'currency' => 'EUR',
        'locale' => 'sk',
    ])->assertCreated();

    $entry = ActivityLog::where('user_id', $user->id)->where('event', 'client.created')->firstOrFail();

    expect($entry->subject_type)->toBe('client')
        ->and($entry->actor_id)->toBe($user->id);
});

it('records an invoice.sent entry when an invoice moves from draft to sent', function (): void {
    $user = createUser();
    $client = asAccount($user, fn () => Client::factory()->create(['user_id' => $user->id]));
    $invoice = Invoice::factory()->create([
        'user_id' => $user->id,
        'client_id' => $client->id,
        'status' => InvoiceStatus::Draft->value,
    ]);

    $this->actingAs($user)
        ->postJson("/api/v1/invoices/{$invoice->id}/status", [
            'status' => 'sent',
        ])
        ->assertOk();

    $entry = ActivityLog::where('subject_type', 'invoice')
        ->where('subject_id', $invoice->id)
        ->where('event', 'invoice.sent')
        ->firstOrFail();

    expect($entry->user_id)->toBe($user->id)
        ->and($entry->actor_id)->toBe($user->id);
});

it('records a user.registered entry on self-registration', function (): void {
    config()->set('qasa.features.registration', true);

    $this->postJson('/api/v1/auth/register', [
        'name' => 'Ján',
        'surname' => 'Novák',
        'email' => 'jan.activity@example.com',
        'password' => 'super-secret-1',
        'accepted_terms' => true,
    ])->assertCreated();

    // Registered through HTTP, so nothing is bound once the request has
    // terminated — users is tenant-scoped too now (phase 7), so the fetch
    // needs AccountLookup to find the account first.
    $account = AccountLookup::byEmail('jan.activity@example.com') ?? throw new RuntimeException('account not found');
    [$user, $entry] = asAccount($account, function (): array {
        $user = User::query()->where('email', 'jan.activity@example.com')->firstOrFail();

        $entry = ActivityLog::withoutGlobalScope('user')
            ->where('user_id', $user->id)
            ->where('event', 'user.registered')
            ->firstOrFail();

        return [$user, $entry];
    });

    expect($entry->subject_id)->toBe($user->id);
});

it("lists only the authenticated account's activity, newest first, filterable by subject", function (): void {
    $user = createUser();
    $other = createUser();

    $client = asAccount($user, function () use ($user) {
        $client = Client::factory()->create(['user_id' => $user->id]);

        ActivityLog::factory()->count(2)->create([
            'user_id' => $user->id,
            'subject_type' => 'client',
            'subject_id' => $client->id,
            'event' => 'client.updated',
        ]);

        return $client;
    });

    ActivityLog::factory()->create(['user_id' => $other->id]);

    $response = $this->actingAs($user)->getJson('/api/v1/activity');

    $response->assertOk()->assertJsonCount(2, 'data');

    $filtered = $this->actingAs($user)->getJson("/api/v1/activity?subject_type=client&subject_id={$client->id}");
    $filtered->assertOk()->assertJsonCount(2, 'data');
});

it('rejects unauthenticated access', function (): void {
    $this->getJson('/api/v1/activity')->assertUnauthorized();
});

it('purges activity log entries outside the retention window', function (): void {
    $user = createUser();

    $old = ActivityLog::factory()->create(['user_id' => $user->id]);
    $old->created_at = now()->subDays(731);
    $old->save();

    $recent = ActivityLog::factory()->create(['user_id' => $user->id]);
    $recent->created_at = now()->subDays(10);
    $recent->save();

    $this->artisan('qasa:activity:purge')->assertSuccessful();

    expect(ActivityLog::query()->find($old->id))->toBeNull()
        ->and(ActivityLog::query()->find($recent->id))->not->toBeNull();
});

it('respects a configurable retention window', function (): void {
    config(['activity.retention_days' => 30]);

    $user = createUser();

    $outsideWindow = ActivityLog::factory()->create(['user_id' => $user->id]);
    $outsideWindow->created_at = now()->subDays(31);
    $outsideWindow->save();

    $insideWindow = ActivityLog::factory()->create(['user_id' => $user->id]);
    $insideWindow->created_at = now()->subDays(29);
    $insideWindow->save();

    $this->artisan('qasa:activity:purge');

    expect(ActivityLog::query()->find($outsideWindow->id))->toBeNull()
        ->and(ActivityLog::query()->find($insideWindow->id))->not->toBeNull();
});
