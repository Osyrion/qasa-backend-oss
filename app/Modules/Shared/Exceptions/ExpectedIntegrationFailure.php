<?php

declare(strict_types=1);

namespace App\Modules\Shared\Exceptions;

/**
 * An integration failure that is sometimes our problem and sometimes just a
 * fact about the tenant's account — a revoked OAuth token, a BYOK key the
 * provider no longer accepts, an access point that does not offer inbound at
 * all. The distinction lives in a flag rather than in a subclass, so
 * `dontReport(SomeException::class)` would either report the whole class or
 * silence it entirely; this interface lets bootstrap/app.php ask the instance.
 *
 * It is the same judgement DomainException already gets: a rule saying no is
 * an answer, not a fault. The difference is that these classes carry both
 * kinds, so only half of each belongs in the error monitor.
 *
 * Declared in Shared because bootstrap/app.php ships verbatim into the OSS
 * build and must not name a premium class — every implementation so far lives
 * in a premium module.
 */
interface ExpectedIntegrationFailure
{
    /**
     * True when this instance is a known, actionable-by-the-tenant outcome
     * that nobody should be paged for.
     */
    public function isExpected(): bool;
}
