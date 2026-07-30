<?php

declare(strict_types=1);

use App\Modules\Shared\Presentation\Middleware\BindTenantContext;
use App\Modules\Shared\Support\TenantContext;
use App\Modules\Shared\Support\TenantQueue;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

/**
 * A job as the worker hands it to the listeners: a payload and an identity.
 *
 * @param  array<string, mixed>  $payload
 */
function fakeJob(array $payload): Job
{
    $job = Mockery::mock(Job::class);
    $job->allows('payload')->andReturns($payload);
    $job->allows('uuid')->andReturns((string) Str::uuid());

    /** @var Job $job */
    return $job;
}

/**
 * The payload a real dispatch writes, read back off the queue.
 *
 * @return array<string, mixed>
 */
function queuedPayload(): array
{
    config(['queue.default' => 'database']);

    DB::table('jobs')->delete();
    dispatch(function (): void {});

    /** @var object{payload: string} $row */
    $row = DB::table('jobs')->orderByDesc('id')->firstOrFail();

    /** @var array<string, mixed> $payload */
    $payload = json_decode($row->payload, true, 512, JSON_THROW_ON_ERROR);

    return $payload;
}

/**
 * The connection's account binding is what Row Level Security will read, so
 * a stale value is not a cosmetic bug — it is the cross-account leak the
 * policies exist to prevent. This is the guard the plan requires before any
 * policy is written.
 *
 * The binding has to be observed from inside a request: the middleware clears
 * it once the response is sent, so asserting after the call would only ever
 * see null.
 */
beforeEach(function (): void {
    // Bare auth, without the api group: this file is about the binding, and
    // the group's subscription gate would 403 a team member before the
    // request ever reaches a route.
    Route::middleware('auth:sanctum')
        ->get('/_test/tenant-context', fn (): array => ['tenant' => TenantContext::current()]);

    // No auth at all — BindTenantContext is global, so it still runs.
    Route::get('/_test/tenant-context-public', fn (): array => ['tenant' => TenantContext::current()]);
});

it('binds the connection to the authenticated account', function (): void {
    $user = createUser();

    $this->actingAs($user)->getJson('/_test/tenant-context')
        ->assertOk()
        ->assertJsonPath('tenant', $user->id);
});

it('binds a team member to the account owner, not to themselves', function (): void {
    // This is what accountOwnerId() exists for, and the single reason the
    // policy cannot simply compare against auth()->id().
    //
    // A real token rather than actingAs(): actingAs() injects the user
    // straight into the guard, skipping token resolution entirely — but
    // token resolution is what binds the connection now that
    // personal_access_tokens is protected too (phase 7), and
    // EnsureMemberSeatActive, a global middleware, reads the member's
    // account before the route's own auth:sanctum middleware would bind
    // it. In production the first call to $request->user('sanctum')
    // anywhere triggers that resolution; actingAs() has nothing to trigger.
    $owner = createSaasUser();
    subscribeToPaidPlan($owner);
    $member = createSaasUser(['owner_id' => $owner->id]);
    $token = $member->createToken('member-token')->plainTextToken;

    $this->withToken($token)->getJson('/_test/tenant-context')
        ->assertOk()
        ->assertJsonPath('tenant', $owner->id);
})->skip(fn (): bool => config('qasa.edition') === 'oss', 'team members are a SaaS concept');

it('leaves nothing behind once the request is over', function (): void {
    TenantContext::set(createUser()->accountOwnerId());

    // Asserted against the middleware rather than after a test request: the
    // test harness rebinds once a request has been handled, so its own
    // assertions can still see the account it acts as. What production does
    // is this.
    (new BindTenantContext)->terminate(Request::create('/'), new Response);

    // Without it the next request on a reused connection — a worker, or
    // anything under Octane — would start out bound to this account.
    expect(TenantContext::current())->toBeNull();
});

it('does not carry one account binding into the next request', function (): void {
    $first = createUser();
    $second = createUser();

    $this->actingAs($first)->getJson('/_test/tenant-context')
        ->assertJsonPath('tenant', $first->id);

    $this->actingAs($second)->getJson('/_test/tenant-context')
        ->assertJsonPath('tenant', $second->id);
});

it('leaves an unauthenticated request unbound', function (): void {
    TenantContext::set(createUser()->id);

    // The middleware clears on the way in, so a public endpoint never
    // inherits whatever the connection was last used for.
    $this->getJson('/_test/tenant-context-public')
        ->assertOk()
        ->assertJsonPath('tenant', null);
});

it('binds a job to the account it was dispatched for', function (): void {
    $user = createUser();
    $job = fakeJob([TenantQueue::PAYLOAD_KEY => $user->id]);

    // What a worker looks like: no request, no authenticated user, nothing
    // bound. Everything the job is about is invisible until the payload says
    // whose it is.
    TenantContext::clear();

    Event::dispatch(new JobProcessing('database', $job));
    expect(TenantContext::current())->toBe($user->id);

    Event::dispatch(new JobProcessed('database', $job));
    expect(TenantContext::current())->toBeNull();
});

it('does not let one job leave its account to the next', function (): void {
    $user = createUser();
    $first = fakeJob([TenantQueue::PAYLOAD_KEY => $user->id]);
    $second = fakeJob([]);

    TenantContext::clear();

    Event::dispatch(new JobProcessing('database', $first));
    Event::dispatch(new JobProcessed('database', $first));

    // A job dispatched with nothing bound stays unbound rather than
    // inheriting whoever the worker ran a moment ago.
    Event::dispatch(new JobProcessing('database', $second));
    expect(TenantContext::current())->toBeNull();
});

it('restores the binding even when a job throws', function (): void {
    $user = createUser();
    $job = fakeJob([]);

    TenantContext::set($user->id);

    // JobProcessed never fires for a failed job. Without the other two
    // outcomes releasing it, the value parked here would be handed to
    // whichever job released next.
    Event::dispatch(new JobProcessing('database', $job));
    Event::dispatch(new JobExceptionOccurred('database', $job, new RuntimeException('boom')));
    Event::dispatch(new JobFailed('database', $job, new RuntimeException('boom')));

    expect(TenantContext::current())->toBe($user->id);
});

it('keeps the account when a job runs inside the request that dispatched it', function (): void {
    $user = createUser();

    // The sync driver and dispatchSync both run a job mid-request. The
    // payload records the dispatching account, so the job is handed the same
    // binding the request had — which is what lets a queued listener re-read
    // the model it was given as it deserialises.
    TenantContext::set($user->id);
    $job = fakeJob([TenantQueue::PAYLOAD_KEY => $user->id]);

    Event::dispatch(new JobProcessing('sync', $job));
    expect(TenantContext::current())->toBe($user->id);

    Event::dispatch(new JobProcessed('sync', $job));
    expect(TenantContext::current())->toBe($user->id);
});

it('writes the dispatching account into the job payload', function (): void {
    $user = createUser();

    TenantContext::set($user->id);

    // Dispatch is the last moment the account is knowable. Everything above
    // depends on it being recorded here.
    expect(queuedPayload())->toHaveKey(TenantQueue::PAYLOAD_KEY, $user->id);

    TenantContext::clear();
    expect(queuedPayload())->toHaveKey(TenantQueue::PAYLOAD_KEY, null);
});

it('reads back exactly what was set, and nothing when unbound', function (): void {
    $user = createUser();

    TenantContext::set($user->id);
    expect(TenantContext::current())->toBe($user->id);

    TenantContext::clear();
    expect(TenantContext::current())->toBeNull();
});
