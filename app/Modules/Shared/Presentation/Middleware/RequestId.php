<?php

declare(strict_types=1);

namespace App\Modules\Shared\Presentation\Middleware;

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
 */
class RequestId
{
    public const HEADER = 'X-Request-Id';

    public function handle(Request $request, Closure $next): Response
    {
        $requestId = $request->header(self::HEADER) ?: (string) Str::uuid();

        $request->attributes->set('request_id', $requestId);
        Log::withContext(['request_id' => $requestId]);

        /** @var Response $response */
        $response = $next($request);
        $response->headers->set(self::HEADER, $requestId);

        return $response;
    }
}
