<?php

declare(strict_types=1);

use App\Modules\Shared\Domain\Models\ActivityLog;

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

it('purges entries across tenants', function (): void {
    $userA = createUser();
    $userB = createUser();

    $entryA = ActivityLog::factory()->create(['user_id' => $userA->id]);
    $entryA->created_at = now()->subDays(731);
    $entryA->save();

    $entryB = ActivityLog::factory()->create(['user_id' => $userB->id]);
    $entryB->created_at = now()->subDays(731);
    $entryB->save();

    $this->artisan('qasa:activity:purge');

    expect(ActivityLog::query()->find($entryA->id))->toBeNull()
        ->and(ActivityLog::query()->find($entryB->id))->toBeNull();
});
