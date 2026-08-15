<?php

declare(strict_types=1);

namespace App\Modules\Shared\Presentation\Middleware;

use App\Modules\Shared\Infrastructure\Sentry\SentryContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Correlates every log line for a request (and any queue job it enqueues,
 * when the job carries the id forward) under one request_id — a caller-
 * supplied X-Request-Id is trusted so a request that hops through a
 * gateway/frontend keeps the same id end to end; otherwise a fresh uuid.
 *
 * The same id is tagged onto the Sentry scope, which is what turns an event
 * back into the log lines that led to it.
 */
class RequestId
{
    public const HEADER = 'X-Request-Id';

    public function handle(Request $request, Closure $next): Response
    {
        $requestId = $this->resolveRequestId($request);

        $request->attributes->set('request_id', $requestId);
        Log::withContext(['request_id' => $requestId]);
        SentryContext::tagRequest($requestId);

        /** @var Response $response */
        $response = $next($request);
        $response->headers->set(self::HEADER, $requestId);

        return $response;
    }

    /**
     * A caller-supplied id is trusted only when it is a short, safe token —
     * it lands in the log context and is echoed back in the response header,
     * so an arbitrary value would let a caller forge log lines (embedded
     * newlines) or bloat the logs with megabyte-long ids. Anything failing
     * the allowlist is replaced with a fresh uuid.
     */
    private function resolveRequestId(Request $request): string
    {
        $candidate = $request->header(self::HEADER);

        if (is_string($candidate) && preg_match('/^[A-Za-z0-9._-]{1,128}$/', $candidate) === 1) {
            return $candidate;
        }

        return (string) Str::uuid();
    }
}
