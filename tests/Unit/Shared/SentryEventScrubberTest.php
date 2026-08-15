<?php

declare(strict_types=1);

use App\Modules\Shared\Application\Services\AiPayloadSanitizer;
use App\Modules\Shared\Infrastructure\Sentry\EventScrubber;
use Sentry\Breadcrumb;
use Sentry\Event;
use Sentry\ExceptionDataBag;
use Sentry\Frame;
use Sentry\Options;
use Sentry\Serializer\RepresentationSerializer;
use Sentry\Stacktrace;
use Sentry\StacktraceBuilder;

function scrub(Event $event): Event
{
    return (new EventScrubber(new AiPayloadSanitizer))->scrub($event);
}

/**
 * Builds a stacktrace the way the SDK does for a real exception — through
 * StacktraceBuilder, not by hand — so the frames carry whatever PHP itself
 * put in the backtrace. That is the whole point: the argument values are not
 * something this test invents.
 *
 * zend.exception_ignore_args is pinned off for the throw because it decides
 * whether PHP records arguments at all, and it is not the same everywhere:
 * the compiled-in default (and the application image) is off, while
 * php.ini-production — what shivammathur/setup-php installs on CI — turns it
 * on. Left to the environment, the arrange half of the test below asserts a
 * leak that the CI runner had already closed for unrelated reasons, and the
 * scrubber goes unexercised on the machine that gates every merge.
 */
function stacktraceLeaking(string $secret): Stacktrace
{
    $options = new Options(['dsn' => null]);
    $builder = new StacktraceBuilder($options, new RepresentationSerializer($options));

    $sign = function (string $webhookSecret): never {
        throw new RuntimeException('signing failed');
    };

    $ignoreArgs = ini_set('zend.exception_ignore_args', '0');

    try {
        $sign($secret);
    } catch (Throwable $e) {
        return $builder->buildFromException($e);
    } finally {
        if ($ignoreArgs !== false) {
            ini_set('zend.exception_ignore_args', $ignoreArgs);
        }
    }
}

function frameVars(?Stacktrace $stacktrace): string
{
    expect($stacktrace)->not->toBeNull();
    assert($stacktrace instanceof Stacktrace);

    return json_encode(array_map(
        fn (Frame $frame): array => $frame->getVars(),
        $stacktrace->getFrames(),
    ), JSON_THROW_ON_ERROR);
}

/*
 * The reason this class exists. sentry-php builds every frame from the
 * backtrace *including* the called function's arguments (FrameBuilder) and
 * serializes them (StacktraceFrameSeralizerTrait); the image runs with
 * zend.exception_ignore_args off, so exception traces carry them too. This
 * repository hands WebhookEndpoint::$secret — an encrypted column — to
 * WebhookSender::send() as a plain argument, so any exception below that call
 * would ship it to a third party.
 *
 * The first expectation is the arrange half of the test: it asserts the leak
 * is real before asserting it is closed. Drop the setVars([]) call from
 * EventScrubber and this test fails on the second half.
 */
it('drops function arguments from stack frames', function (): void {
    $secret = 'whsec_live_should_never_leave_the_process';
    $stacktrace = stacktraceLeaking($secret);

    expect(frameVars($stacktrace))->toContain($secret);

    $event = Event::createEvent();
    $event->setExceptions([new ExceptionDataBag(new RuntimeException('signing failed'), $stacktrace)]);

    $scrubbed = scrub($event);

    expect(frameVars($scrubbed->getExceptions()[0]->getStacktrace()))->not->toContain($secret);
});

it('drops function arguments from an event-level stacktrace', function (): void {
    $secret = 'sk_live_platform_key';

    $event = Event::createEvent();
    $event->setStacktrace(stacktraceLeaking($secret));

    expect(frameVars(scrub($event)->getStacktrace()))->not->toContain($secret);
});

it('masks credentials by key name wherever they appear', function (string $key): void {
    $event = Event::createEvent();
    $event->setRequest(['data' => [$key => 'the-actual-value']]);

    expect(scrub($event)->getRequest()['data'][$key])->toBe('[filtered]');
})->with([
    'Authorization header' => ['Authorization'],
    'lowercased header' => ['authorization'],
    'cookies' => ['cookies'],
    'password' => ['password'],
    'bearer token' => ['access_token'],
    'BYOK key' => ['api_key'],
    'Peppol client secret' => ['client_secret'],
    'inbox token' => ['email_inbox_token'],
    'TOTP secret' => ['two_factor_secret'],
    'header casing with dashes' => ['X-Api-Key'],
]);

it('leaves ordinary request fields alone', function (): void {
    $event = Event::createEvent();
    $event->setRequest([
        'url' => 'https://api.example/api/v1/invoices',
        'method' => 'POST',
        'data' => ['client_id' => '01JX', 'currency' => 'EUR'],
    ]);

    expect(scrub($event)->getRequest()['data'])->toBe(['client_id' => '01JX', 'currency' => 'EUR']);
});

/*
 * AiPayloadSanitizer is the repo's existing IBAN / birth-number scrubber
 * (MCP plan, phase 3 part C). Reused here rather than reimplemented, so a
 * pattern added there covers Sentry too.
 */
it('redacts personal data the sanitizer knows about, in messages and exception values', function (): void {
    $event = Event::createEvent();
    $event->setMessage('payment to SK3112000000198742637541 failed');
    $event->setExceptions([
        new ExceptionDataBag(new RuntimeException('no match for 8351121234 in the statement')),
    ]);

    $scrubbed = scrub($event);

    expect($scrubbed->getMessage())->toBe('payment to [REDACTED_IBAN] failed')
        ->and($scrubbed->getExceptions()[0]->getValue())->toBe('no match for [REDACTED_ID] in the statement');
});

it('scrubs breadcrumb metadata while keeping the breadcrumb', function (): void {
    $event = Event::createEvent();
    $event->setBreadcrumb([
        new Breadcrumb(Breadcrumb::LEVEL_INFO, Breadcrumb::TYPE_DEFAULT, 'http', 'fio statement request', [
            'url' => 'https://fioapi.fio.cz/v1/rest/periods',
            'token' => 'fio-live-token',
        ]),
    ]);

    $breadcrumb = scrub($event)->getBreadcrumbs()[0];

    expect($breadcrumb->getCategory())->toBe('http')
        ->and($breadcrumb->getMessage())->toBe('fio statement request')
        ->and($breadcrumb->getMetadata()['url'])->toBe('https://fioapi.fio.cz/v1/rest/periods')
        ->and($breadcrumb->getMetadata()['token'])->toBe('[filtered]');
});

it('scrubs nested extra context', function (): void {
    $event = Event::createEvent();
    $event->setExtra(['connection' => ['provider' => 'fio', 'access_token' => 'live']]);

    expect(scrub($event)->getExtra()['connection'])->toBe(['provider' => 'fio', 'access_token' => '[filtered]']);
});
