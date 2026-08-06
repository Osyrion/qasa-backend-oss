<?php

declare(strict_types=1);

use App\Modules\Auth\Domain\Models\User;
use App\Modules\Shared\Domain\Models\ActivityLog;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;

/**
 * Runs qasa:activity:verify-chain and returns its exit code + full output.
 *
 * Deliberately not $this->artisan(...)->expectsOutputToContain(): that helper
 * mocks the output through Symfony's console formatter, which word-wraps
 * long lines to a narrow width in that test harness specifically — a
 * substring can straddle the wrap point and land in two separate writes,
 * making a real match report as "not found". Artisan::call() + Artisan::
 * output() captures the same text unwrapped.
 *
 * @return array{0: int, 1: string}
 */
function runVerifyChain(?string $accountId = null): array
{
    $exitCode = Artisan::call('qasa:activity:verify-chain', $accountId !== null ? ['--account' => $accountId] : []);

    return [$exitCode, Artisan::output()];
}

/**
 * ENGINEERING_GUARDRAILS_PLAN.md part C: hash-chained activity log
 * (prev_hash/row_hash, qasa:activity:verify-chain, purge boundary).
 */

/**
 * Builds a chained entry directly, bypassing HTTP/the recorder — needed to
 * fabricate an old, already-chained entry with a specific created_at (the
 * chain hash covers created_at, so backdating a row after creation would
 * invalidate its own hash instead of simulating an aged one).
 *
 * @param  array<string, mixed>  $changes
 */
function chainedActivityEntry(User $user, string $event, Carbon $createdAt, ?string $prevHash, array $changes = []): ActivityLog
{
    // activity_log.created_at is timestamp(0); truncate before hashing so
    // the hashed value matches what Postgres actually persists (see the
    // matching comment in EloquentActivityRecorder).
    $createdAt = $createdAt->copy()->startOfSecond();
    $subjectId = (string) Str::uuid();
    $rowHash = ActivityLog::computeRowHash($user->id, $user->id, 'client', $subjectId, $event, $changes, (string) $createdAt->toISOString(), $prevHash);

    return ActivityLog::create([
        'user_id' => $user->id,
        'actor_id' => $user->id,
        'subject_type' => 'client',
        'subject_id' => $subjectId,
        'event' => $event,
        'changes' => $changes,
        'created_at' => $createdAt,
        'prev_hash' => $prevHash,
        'row_hash' => $rowHash,
    ]);
}

it('chains consecutive activity entries for the same account', function (): void {
    $user = createUser();

    $this->actingAs($user)->postJson('/api/v1/clients', [
        'client_type' => 'company',
        'company_name' => 'First s.r.o.',
        'is_vat_payer' => false,
        'country' => 'SK',
        'currency' => 'EUR',
        'locale' => 'sk',
    ])->assertCreated();

    $this->actingAs($user)->postJson('/api/v1/clients', [
        'client_type' => 'company',
        'company_name' => 'Second s.r.o.',
        'is_vat_payer' => false,
        'country' => 'SK',
        'currency' => 'EUR',
        'locale' => 'sk',
    ])->assertCreated();

    $entries = ActivityLog::where('user_id', $user->id)->orderBy('created_at')->orderBy('id')->get();
    expect($entries)->toHaveCount(2);

    $first = ActivityLog::where('user_id', $user->id)->orderBy('created_at')->orderBy('id')->firstOrFail();
    $second = ActivityLog::where('user_id', $user->id)->orderByDesc('created_at')->orderByDesc('id')->firstOrFail();

    expect($first->prev_hash)->toBeNull()
        ->and($first->row_hash)->not->toBeNull()
        ->and($second->prev_hash)->toBe($first->row_hash)
        ->and($second->row_hash)->not->toBe($first->row_hash);
});

it('starts an independent chain per account', function (): void {
    $userA = createUser();
    $userB = createUser();

    $this->actingAs($userA)->postJson('/api/v1/clients', [
        'client_type' => 'company', 'company_name' => 'A s.r.o.', 'is_vat_payer' => false,
        'country' => 'SK', 'currency' => 'EUR', 'locale' => 'sk',
    ])->assertCreated();

    $this->actingAs($userB)->postJson('/api/v1/clients', [
        'client_type' => 'company', 'company_name' => 'B s.r.o.', 'is_vat_payer' => false,
        'country' => 'SK', 'currency' => 'EUR', 'locale' => 'sk',
    ])->assertCreated();

    // The connection is left bound to whichever account last ran an HTTP
    // request (userB) — reading userA's rows needs an explicit rebind, same
    // as ActivityLogTest's "user.registered" case.
    $entryA = asAccount($userA, fn (): ActivityLog => ActivityLog::withoutGlobalScope('user')->where('user_id', $userA->id)->firstOrFail());
    $entryB = ActivityLog::withoutGlobalScope('user')->where('user_id', $userB->id)->firstOrFail();

    expect($entryA->prev_hash)->toBeNull()
        ->and($entryB->prev_hash)->toBeNull()
        ->and($entryA->row_hash)->not->toBe($entryB->row_hash);
});

it('reports the chain intact via qasa:activity:verify-chain', function (): void {
    $user = createUser();

    $this->actingAs($user)->postJson('/api/v1/clients', [
        'client_type' => 'company', 'company_name' => 'ACME s.r.o.', 'is_vat_payer' => false,
        'country' => 'SK', 'currency' => 'EUR', 'locale' => 'sk',
    ])->assertCreated();

    [$exitCode, $output] = runVerifyChain($user->id);

    expect($exitCode)->toBe(0)
        ->and($output)->toContain('chain intact');
});

it('detects a tampered entry (content changed after the fact)', function (): void {
    $user = createUser();

    $this->actingAs($user)->postJson('/api/v1/clients', [
        'client_type' => 'company', 'company_name' => 'ACME s.r.o.', 'is_vat_payer' => false,
        'country' => 'SK', 'currency' => 'EUR', 'locale' => 'sk',
    ])->assertCreated();

    $entry = ActivityLog::where('user_id', $user->id)->firstOrFail();
    $entry->newQuery()->where('id', $entry->id)->update(['event' => 'client.deleted']);

    [$exitCode, $output] = runVerifyChain($user->id);

    expect($exitCode)->toBe(1)
        ->and($output)->toContain('BROKEN')
        ->and($output)->toContain("does not match this entry's own content");
});

it('detects a forged prev_hash that does not lead back to the previous entry', function (): void {
    $user = createUser();

    $this->actingAs($user)->postJson('/api/v1/clients', [
        'client_type' => 'company', 'company_name' => 'First s.r.o.', 'is_vat_payer' => false,
        'country' => 'SK', 'currency' => 'EUR', 'locale' => 'sk',
    ])->assertCreated();

    $this->actingAs($user)->postJson('/api/v1/clients', [
        'client_type' => 'company', 'company_name' => 'Second s.r.o.', 'is_vat_payer' => false,
        'country' => 'SK', 'currency' => 'EUR', 'locale' => 'sk',
    ])->assertCreated();

    $second = ActivityLog::where('user_id', $user->id)->orderByDesc('created_at')->orderByDesc('id')->firstOrFail();
    // Forge a plausible-looking but wrong prev_hash, then recompute row_hash
    // to match it — otherwise the content-hash check above would catch this
    // first, and this test would not be isolating the link check.
    $forgedPrevHash = hash('sha256', 'not-the-real-previous-hash');
    $forgedRowHash = ActivityLog::computeRowHash(
        $second->user_id, $second->actor_id, $second->subject_type, $second->subject_id,
        $second->event, $second->changes ?? [], (string) $second->created_at?->toISOString(), $forgedPrevHash,
    );
    $second->newQuery()->where('id', $second->id)->update(['prev_hash' => $forgedPrevHash, 'row_hash' => $forgedRowHash]);

    [$exitCode, $output] = runVerifyChain($user->id);

    expect($exitCode)->toBe(1)
        ->and($output)->toContain('BROKEN')
        ->and($output)->toContain('prev_hash matches neither');
});

it('lets purge cut the chain and writes a documented anchor that verify-chain accepts', function (): void {
    config(['activity.retention_days' => 30]);
    $user = createUser();

    $first = chainedActivityEntry($user, 'client.created', now()->subDays(40), null);
    $second = chainedActivityEntry($user, 'client.updated', now()->subDays(35), $first->row_hash);
    $recent = chainedActivityEntry($user, 'client.updated', now()->subDays(5), $second->row_hash);

    $this->artisan('qasa:activity:purge')->assertSuccessful();

    expect(ActivityLog::query()->find($first->id))->toBeNull()
        ->and(ActivityLog::query()->find($second->id))->toBeNull()
        ->and(ActivityLog::query()->find($recent->id))->not->toBeNull();

    $anchor = ActivityLog::where('user_id', $user->id)->where('event', 'activity.purged')->firstOrFail();

    expect($anchor->changes['purged_through_hash'] ?? null)->toBe($second->row_hash)
        ->and($anchor->changes['purged_count'] ?? null)->toBe(2)
        ->and($recent->prev_hash)->toBe($second->row_hash) // the now-dangling link the anchor documents
        ->and($anchor->prev_hash)->toBe($recent->row_hash); // the anchor itself chains normally onto what survived

    [$exitCode, $output] = runVerifyChain($user->id);

    expect($exitCode)->toBe(0)
        ->and($output)->toContain('chain intact');
});

it('does not write a purge anchor for an account with nothing to purge', function (): void {
    $user = createUser();
    ActivityLog::factory()->create(['user_id' => $user->id, 'created_at' => now()->subDays(5)]);

    $this->artisan('qasa:activity:purge')->assertSuccessful();

    expect(ActivityLog::where('user_id', $user->id)->where('event', 'activity.purged')->exists())->toBeFalse();
});

it('tolerates a legacy unchained row (row_hash null) ahead of the real chain — the shape pre-backfill/pre-feature accounts are in', function (): void {
    $user = createUser();

    // A row created before this feature existed, or before the backfill
    // migration reaches it — never produced by the recorder itself.
    ActivityLog::factory()->create([
        'user_id' => $user->id,
        'created_at' => now()->subDays(2),
        'prev_hash' => null,
        'row_hash' => null,
    ]);

    $this->actingAs($user)->postJson('/api/v1/clients', [
        'client_type' => 'company', 'company_name' => 'ACME s.r.o.', 'is_vat_payer' => false,
        'country' => 'SK', 'currency' => 'EUR', 'locale' => 'sk',
    ])->assertCreated();

    $chained = ActivityLog::where('user_id', $user->id)->whereNotNull('row_hash')->firstOrFail();
    expect($chained->prev_hash)->toBeNull(); // starts its own chain, unaware of the unchained row before it

    [$exitCode, $output] = runVerifyChain($user->id);

    expect($exitCode)->toBe(0)
        ->and($output)->toContain('chain intact');
});
