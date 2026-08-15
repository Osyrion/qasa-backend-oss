<?php

declare(strict_types=1);

use App\Modules\Shared\Exceptions\DomainException;
use App\Modules\Shared\Exceptions\ExpectedIntegrationFailure;
use Illuminate\Contracts\Debug\ExceptionHandler;

/**
 * The mechanism only, with a stand-in exception — which module's failures
 * answer true is each module's own test. Nothing premium is named here
 * because this file survives into the generated OSS core.
 */
final class FakeIntegrationFailure extends RuntimeException implements ExpectedIntegrationFailure
{
    public function __construct(private readonly bool $expected)
    {
        parent::__construct('provider said no');
    }

    public function isExpected(): bool
    {
        return $this->expected;
    }
}

function shouldReport(Throwable $e): bool
{
    return app(ExceptionHandler::class)->shouldReport($e);
}

it('does not report an integration failure the tenant has to fix', function (): void {
    expect(shouldReport(new FakeIntegrationFailure(expected: true)))->toBeFalse();
});

it('still reports the same class when the failure is ours', function (): void {
    expect(shouldReport(new FakeIntegrationFailure(expected: false)))->toBeTrue();
});

/*
 * Guards the two rules against each other: the older dontReport(DomainException)
 * and the new instance-level filter have to coexist, and an ordinary exception
 * must keep reaching the reporter — a filter that swallowed everything would
 * pass a test that only checks the silenced cases.
 */
it('leaves the existing rules alone', function (): void {
    expect(shouldReport(DomainException::because('a rule said no')))->toBeFalse()
        ->and(shouldReport(new RuntimeException('something actually broke')))->toBeTrue();
});
