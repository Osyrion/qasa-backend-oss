<?php

declare(strict_types=1);

namespace App\Modules\Shared\Infrastructure\Sentry;

use App\Modules\Shared\Application\Services\AiPayloadSanitizer;
use Sentry\Breadcrumb;
use Sentry\Event;
use Sentry\EventHint;
use Sentry\Stacktrace;

/**
 * The one place that decides what may leave the process on its way to Sentry.
 * Wired in as `before_send` (config/sentry.php), so it sees every event,
 * whatever captured it.
 *
 * The part that actually matters is the stack frames. sentry-php builds every
 * frame from debug_backtrace() *with* the called function's arguments and
 * serializes them into the payload (Frame::getVars(),
 * StacktraceFrameSeralizerTrait) — there is no option to turn that off. This
 * repository decrypts ten `encrypted` cast columns into memory, and one of
 * them is handed around as a plain argument: WebhookSender::send() takes the
 * endpoint's HMAC signing secret. A single exception thrown anywhere below
 * that call would ship the secret to a third party. Bank access tokens,
 * Google refresh tokens, BYOK API keys and the database password reach Sentry
 * the same way. So frame arguments are dropped wholesale — the file, line and
 * function name are what makes a trace readable, the argument values are not
 * worth the risk of auditing case by case.
 *
 * Everything else is defence in depth: `send_default_pii` is already off, so
 * the request body and cookies should not be attached at all, but a key named
 * like a credential is masked wherever it appears, and free text is passed
 * through AiPayloadSanitizer — the repo's existing IBAN / birth-number
 * scrubber, reused rather than re-implemented.
 */
final class EventScrubber
{
    private const string MASK = '[filtered]';

    /**
     * Matched as substrings against lowercased keys, so `stripe_secret`,
     * `X-Api-Key` and `two_factor_recovery_codes` are all covered without
     * enumerating them.
     */
    private const array SENSITIVE_KEY_FRAGMENTS = [
        'authorization',
        'cookie',
        'password',
        'passwd',
        'secret',
        'token',
        'api_key',
        'apikey',
        'credential',
        'private_key',
        'signature',
        'two_factor',
        'recovery_code',
    ];

    public function __construct(private readonly AiPayloadSanitizer $sanitizer) {}

    /**
     * Entry point named in config/sentry.php. Static because a `before_send`
     * that survives config:cache has to be an array callable, not a closure.
     */
    public static function handle(Event $event, ?EventHint $hint = null): Event
    {
        return (new self(app(AiPayloadSanitizer::class)))->scrub($event);
    }

    public function scrub(Event $event): Event
    {
        $this->dropFrameArguments($event->getStacktrace());

        foreach ($event->getExceptions() as $exception) {
            $this->dropFrameArguments($exception->getStacktrace());
            $exception->setValue($this->sanitizer->sanitize($exception->getValue()));
        }

        $event->setRequest($this->scrubValues($event->getRequest()));
        $event->setExtra($this->scrubValues($event->getExtra()));

        foreach ($event->getContexts() as $name => $context) {
            $event->setContext($name, $this->scrubValues($context));
        }

        $message = $event->getMessage();

        if ($message !== null) {
            $formatted = $event->getMessageFormatted();

            $event->setMessage(
                $this->sanitizer->sanitize($message),
                $this->scrubValues($event->getMessageParams()),
                $formatted === null ? null : $this->sanitizer->sanitize($formatted),
            );
        }

        $event->setBreadcrumb(array_map(
            fn (Breadcrumb $breadcrumb): Breadcrumb => $this->scrubBreadcrumb($breadcrumb),
            $event->getBreadcrumbs(),
        ));

        return $event;
    }

    private function dropFrameArguments(?Stacktrace $stacktrace): void
    {
        if ($stacktrace === null) {
            return;
        }

        foreach ($stacktrace->getFrames() as $frame) {
            $frame->setVars([]);
        }
    }

    /**
     * Breadcrumbs are immutable value objects, so a scrubbed one is a new one
     * built up from the original.
     */
    private function scrubBreadcrumb(Breadcrumb $breadcrumb): Breadcrumb
    {
        $message = $breadcrumb->getMessage();

        $scrubbed = new Breadcrumb(
            $breadcrumb->getLevel(),
            $breadcrumb->getType(),
            $breadcrumb->getCategory(),
            $message === null ? null : $this->sanitizer->sanitize($message),
            [],
            $breadcrumb->getTimestamp(),
        );

        foreach ($this->scrubValues($breadcrumb->getMetadata()) as $key => $value) {
            $scrubbed = $scrubbed->withMetadata((string) $key, $value);
        }

        return $scrubbed;
    }

    /**
     * @param  array<array-key, mixed>  $values
     * @return array<array-key, mixed>
     */
    private function scrubValues(array $values): array
    {
        $scrubbed = [];

        foreach ($values as $key => $value) {
            if ($this->isSensitiveKey($key)) {
                $scrubbed[$key] = self::MASK;

                continue;
            }

            $scrubbed[$key] = match (true) {
                is_array($value) => $this->scrubValues($value),
                is_string($value) => $this->sanitizer->sanitize($value),
                default => $value,
            };
        }

        return $scrubbed;
    }

    private function isSensitiveKey(int|string $key): bool
    {
        if (! is_string($key)) {
            return false;
        }

        // Header names spell the same thing with dashes that config and
        // request payloads spell with underscores (X-Api-Key / api_key), so
        // one list covers both.
        $key = str_replace('-', '_', strtolower($key));

        foreach (self::SENSITIVE_KEY_FRAGMENTS as $fragment) {
            if (str_contains($key, $fragment)) {
                return true;
            }
        }

        return false;
    }
}
