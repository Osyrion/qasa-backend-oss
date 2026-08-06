<?php

declare(strict_types=1);

use App\Modules\Shared\Exceptions\DomainException;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Foundation\Exceptions\Handler;

/**
 * A business rule saying no is an answer, not a fault.
 *
 * Every DomainException that reached the handler used to be reported: "the
 * client has no e-mail address" and "SEPA export requires an IBAN" each wrote
 * an ERROR line and a full stack trace. The dev log was 28 MB of them, which
 * is the real cost — not the disk, but that an actual fault is invisible in
 * the noise.
 *
 * shouldReport() is the contract dontReport() changes, so that is what these
 * assert. Spying on the Log facade would not do it: the handler resolves its
 * own logger, and a spy installed afterwards never sees the call.
 */
it('does not report a business-rule rejection', function (): void {
    /** @var Handler $handler */
    $handler = app(ExceptionHandler::class);

    expect($handler->shouldReport(DomainException::because('a rule said no')))->toBeFalse();
});

it('leaves genuine faults reportable', function (): void {
    /** @var Handler $handler */
    $handler = app(ExceptionHandler::class);

    expect($handler->shouldReport(new RuntimeException('the disk is gone')))->toBeTrue();
});

it('still answers the caller with a 422 and a message', function (): void {
    // This controller does not catch DomainException itself, so the exception
    // travels all the way to the handler — the path that was doing the
    // logging, and the one whose 422 must survive dontReport().
    $user = createUser(['is_vat_payer' => false]);

    $this->actingAs($user)
        ->getJson('/api/v1/reports/vat-control-statement?from=2026-01-01&to=2026-03-31')
        ->assertStatus(422)
        ->assertJsonStructure(['message']);
});
