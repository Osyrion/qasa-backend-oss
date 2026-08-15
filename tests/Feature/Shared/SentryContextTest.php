<?php

declare(strict_types=1);

use App\Modules\Shared\Presentation\Middleware\RequestId;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Log;
use Sentry\Event;
use Sentry\SentrySdk;
use Sentry\State\Scope;

/**
 * Turns Symfony's console events into Laravel's CommandStarting/CommandFinished
 * — the only source of the `command` and `run_id` correlation asserted below.
 *
 * The framework wires this in Kernel::__construct, but skips it when
 * Application::runningUnitTests() is true, so the mechanism under test is
 * switched off in precisely the environment that tests it. Until this call
 * existed the two artisan cases below only passed where APP_ENV was exported
 * as something other than "testing" (docker-compose exports APP_ENV=local, so
 * the local suite was green) and failed on CI, which exports nothing and lets
 * phpunit.xml's APP_ENV=testing stand. Same method the framework calls, and it
 * no-ops on a second call.
 */
function rerouteConsoleEvents(): void
{
    // Resolved through the contract, which is what the container has the
    // singleton under — asking for the concrete class would build a second
    // kernel and leave the one $this->artisan() uses untouched.
    app(ConsoleKernel::class)->rerouteSymfonyCommandEvents();
}

/**
 * Reads back whatever the current scope would put on an event. Without a DSN
 * nothing is transmitted, but the scope is built exactly the same way — which
 * is the part worth asserting.
 */
function scopeEvent(): Event
{
    $event = Event::createEvent();

    SentrySdk::getCurrentHub()->configureScope(function (Scope $scope) use ($event): void {
        $scope->applyToEvent($event);
    });

    return $event;
}

/*
 * An event with no request id is a stack trace with no way back to the log
 * lines that led to it. The header the caller is handed back and the tag on
 * the event have to be the same value, or the correlation is imaginary.
 */
it('tags the event with the same request id the caller is handed back', function (): void {
    $response = $this->get('/up');

    $requestId = $response->headers->get(RequestId::HEADER);

    expect($requestId)->not->toBeNull()
        ->and(scopeEvent()->getTags()['request_id'] ?? null)->toBe($requestId);
});

it('honours a caller-supplied request id so a trace survives the gateway', function (): void {
    $this->withHeader(RequestId::HEADER, 'fe-01JABCDEF')->get('/up');

    expect(scopeEvent()->getTags()['request_id'] ?? null)->toBe('fe-01JABCDEF');
});

/*
 * Identity, without the email that send_default_pii would have attached.
 * The account owner id is what answers "one account or all of them".
 */
it('identifies the authenticated user by id and account, never by email', function (): void {
    $user = createUser();

    $this->actingAs($user)->getJson('/api/v1/auth/me')->assertOk();

    $event = scopeEvent();
    $sentryUser = $event->getUser();

    expect($sentryUser)->not->toBeNull();
    assert($sentryUser !== null);

    expect($sentryUser->getId())->toBe($user->id)
        ->and($sentryUser->getEmail())->toBeNull()
        ->and($sentryUser->getUsername())->toBeNull()
        ->and($sentryUser->getMetadata()['account_owner_id'] ?? null)->toBe($user->accountOwnerId())
        ->and($event->getTags()['account_owner_id'] ?? null)->toBe($user->accountOwnerId());
});

/*
 * 27 artisan commands and every job used to write log lines with nothing
 * tying them together, and would have reached Sentry the same way.
 */
it('correlates an artisan command run', function (): void {
    rerouteConsoleEvents();

    $this->artisan('qasa:privacy:purge-request-metadata')->assertSuccessful();

    $tags = scopeEvent()->getTags();

    expect($tags['command'] ?? null)->toBe('qasa:privacy:purge-request-metadata')
        ->and($tags['run_id'] ?? null)->not->toBeNull();
});

/*
 * A job carries its own uuid, which is also what the failed_jobs row holds —
 * so the tag on the event, the log line and the failed job are all the same
 * string.
 */
it('correlates a queued job', function (): void {
    dispatch(function (): void {});

    $tags = scopeEvent()->getTags();

    expect($tags['job'] ?? null)->toStartWith('Closure')
        ->and($tags['run_id'] ?? null)->not->toBeNull();
});

/*
 * Asserted on a real log line rather than on the withContext() call, because
 * the shared context is only worth anything if it survives into the records
 * the log actually keeps — that is the half a Sentry event has to be matched
 * against.
 */
it('puts the same correlation id in the log context', function (): void {
    rerouteConsoleEvents();

    /** @var list<MessageLogged> $records */
    $records = [];
    Log::listen(function (MessageLogged $message) use (&$records): void {
        $records[] = $message;
    });

    $this->artisan('qasa:privacy:purge-request-metadata')->assertSuccessful();
    Log::info('probe');

    $last = end($records);
    assert($last instanceof MessageLogged);

    $context = $last->context;

    expect($context['command'] ?? null)->toBe('qasa:privacy:purge-request-metadata')
        ->and($context['run_id'] ?? null)->not->toBeNull();
});
