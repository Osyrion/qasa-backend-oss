<?php

declare(strict_types=1);

namespace App\Modules\Shared\Infrastructure\Sentry;

use App\Modules\Shared\Application\Contracts\ErrorReportingContext;
use Sentry\State\Scope;
use Sentry\UserDataBag;

use function Sentry\configureScope;

/**
 * Everything the application chooses to attach to a Sentry event, in one
 * place. `send_default_pii` is off (config/sentry.php), so nothing arrives by
 * accident — an identifier is here because someone decided it earns its place.
 *
 * What earns it:
 *
 * - `request_id` (and `run_id` for work with no request behind it), the same
 *   value that goes into the log context and, for a request, comes back in
 *   the response header. Without it an event is a stack trace with no way
 *   back to the log lines around it, which is most of what makes the log
 *   worth keeping.
 * - the user's id and the account owner's id — enough to answer "is this one
 *   account or all of them", which decides whether something is a bug or a
 *   broken integration. Never the email: that is the line send_default_pii
 *   would have crossed, and there is a Sentry account-id lookup for the rare
 *   case where someone genuinely needs the person.
 */
final class SentryContext implements ErrorReportingContext
{
    public function tagRequest(string $requestId): void
    {
        configureScope(static function (Scope $scope) use ($requestId): void {
            $scope->setTag('request_id', $requestId);
        });
    }

    public function identify(string $userId, string $accountOwnerId): void
    {
        configureScope(static function (Scope $scope) use ($userId, $accountOwnerId): void {
            $scope->setUser(UserDataBag::createFromArray([
                'id' => $userId,
                'account_owner_id' => $accountOwnerId,
            ]));

            // Also a tag, because tags are what Sentry can group and filter
            // an issue by — "this only ever happens to one account" is the
            // fastest way to tell a bug from a broken connection.
            $scope->setTag('account_owner_id', $accountOwnerId);
        });
    }

    /**
     * A queue job or an artisan command. Deliberately `run_id` and not
     * `request_id`: a job dispatched from a request may already carry the
     * request's id forward (DeliverWebhookJob does), and overwriting it would
     * cut the only thread back to the thing that caused the job. The two
     * coexist — run_id says which execution, request_id says which request
     * asked for it.
     */
    public function tagBackgroundRun(string $kind, string $name, string $runId): void
    {
        configureScope(static function (Scope $scope) use ($kind, $name, $runId): void {
            $scope->setTag('run_id', $runId);
            $scope->setTag($kind, $name);
        });
    }
}
