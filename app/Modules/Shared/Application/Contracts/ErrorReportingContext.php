<?php

declare(strict_types=1);

namespace App\Modules\Shared\Application\Contracts;

/**
 * What the application chooses to attach to an error report — correlation
 * ids and identity, never PII (the reasoning is on the Sentry implementation).
 *
 * The contract exists so the two middlewares that know these facts first —
 * RequestId has the correlation id, Authenticate has the identity — can say
 * them without Presentation reaching into Infrastructure for a vendor SDK.
 * Which reporter is listening is a wiring decision, and it stays in the
 * provider.
 */
interface ErrorReportingContext
{
    public function tagRequest(string $requestId): void;

    public function identify(string $userId, string $accountOwnerId): void;

    /**
     * A queue job or an artisan command — work with no request behind it.
     */
    public function tagBackgroundRun(string $kind, string $name, string $runId): void;
}
