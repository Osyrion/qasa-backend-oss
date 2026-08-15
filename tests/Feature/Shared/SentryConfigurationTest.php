<?php

declare(strict_types=1);

use App\Modules\Shared\Infrastructure\Sentry\EventScrubber;
use Sentry\Options;
use Sentry\SentrySdk;

function sentryOptions(): Options
{
    $client = SentrySdk::getCurrentHub()->getClient();
    expect($client)->not->toBeNull();
    assert($client !== null);

    return $client->getOptions();
}

/**
 * config/sentry.php is a plain array the SDK reads once at boot — a renamed
 * key or a typo'd callable does not fail anywhere, it just quietly stops
 * scrubbing. These assertions are about the wiring holding, not about what
 * the scrubber does (SentryEventScrubberTest covers that).
 */
it('routes every event through the scrubber', function (): void {
    expect(sentryOptions()->getBeforeSendCallback())->toBe([EventScrubber::class, 'handle']);
});

/*
 * send_default_pii attaches the request body, cookies, client IP and the
 * authenticated user's email to every event. docs/legal/SUBPROCESSORS.md
 * describes what Sentry receives on the strength of this being off.
 */
it('never sends personal data by default', function (): void {
    expect(sentryOptions()->shouldSendDefaultPii())->toBeFalse();
});

it('keeps performance tracing off until someone opts in', function (): void {
    expect(sentryOptions()->getTracesSampleRate())->toBe(0.0);
});

/*
 * The suite must never be able to reach a real Sentry project, whatever a
 * developer has in their own .env (phpunit.xml pins the DSN empty).
 */
it('has no DSN under test', function (): void {
    expect(sentryOptions()->getDsn())->toBeNull();
});
