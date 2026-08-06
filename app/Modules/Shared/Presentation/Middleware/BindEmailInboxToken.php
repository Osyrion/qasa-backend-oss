<?php

declare(strict_types=1);

namespace App\Modules\Shared\Presentation\Middleware;

use App\Modules\Shared\Support\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Binds a Postmark inbound webhook request to the account whose
 * `{token}@in.<domain>` address received it — same shape as
 * BindPublicDocumentTenant, different source of the token (the request
 * body's recipient field, not a route parameter; Postmark's JSON is already
 * parsed by the time middleware runs). An unknown token leaves the
 * connection unbound; ProcessInboundEmailAction turns that into its own
 * "unknown_token" result rather than a 404, since this is a machine caller,
 * not a browser.
 */
class BindEmailInboxToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $recipient = (string) ($request->input('OriginalRecipient') ?? $request->input('ToFull.0.Email') ?? '');
        $atPosition = strpos($recipient, '@');
        $token = $atPosition !== false && $atPosition > 0 ? substr($recipient, 0, $atPosition) : null;

        if ($token !== null) {
            $account = DB::selectOne('SELECT public.account_for_email_inbox_token(?) AS account_id', [$token]);

            if ($account?->account_id !== null) {
                TenantContext::set((string) $account->account_id);
            }
        }

        return $next($request);
    }
}
