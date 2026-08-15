<?php

declare(strict_types=1);

use App\Modules\Invoicing\Infrastructure\Ocr\LlmExtractionException;

/*
 * The BYOK case: an account pastes its own Anthropic key, the key stops
 * working, and the extraction quietly falls back to regex. That is the
 * account's problem to fix and there is nothing to page anyone about — but a
 * timeout or a response the driver cannot parse is ours.
 */
it('treats a rejected BYOK key as expected', function (): void {
    expect(LlmExtractionException::unauthorized('401 from provider')->isExpected())->toBeTrue();
});

it('keeps every other extraction failure reportable', function (): void {
    expect(LlmExtractionException::because('request timed out')->isExpected())->toBeFalse();
});
