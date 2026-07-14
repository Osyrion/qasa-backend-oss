<?php

declare(strict_types=1);

namespace App\Modules\Shared\Presentation\Middleware;

use App\Modules\Auth\Domain\Models\User;
use App\Modules\Shared\Domain\Models\IdempotencyKey as IdempotencyKeyModel;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Opt-in replay protection for critical POSTs: a client retry (network
 * timeout, double-click) that resends the same Idempotency-Key header gets
 * back the original response instead of creating a duplicate record. Without
 * the header, behavior is unchanged — this never affects existing callers.
 */
class IdempotencyKey
{
    private const HEADER = 'Idempotency-Key';

    private const TTL_HOURS = 24;

    public function handle(Request $request, Closure $next): Response
    {
        $key = $request->header(self::HEADER);

        if ($key === null) {
            return $next($request);
        }

        /** @var User|null $user */
        $user = $request->user();

        if ($user === null) {
            return $next($request);
        }

        $keyHash = hash('sha256', implode('|', [$key, $user->id, $request->method(), $request->path()]));
        $bodyHash = hash('sha256', $request->getContent());

        $existing = IdempotencyKeyModel::query()
            ->where('key_hash', $keyHash)
            ->where('created_at', '>=', now()->subHours(self::TTL_HOURS))
            ->first();

        if ($existing !== null) {
            if ($existing->body_hash !== $bodyHash) {
                return response()->json(
                    ['message' => __('shared.idempotency_key_conflict')],
                    422,
                );
            }

            return response()->json($existing->response_body, $existing->response_status);
        }

        /** @var Response $response */
        $response = $next($request);

        if ($response->getStatusCode() < 500) {
            try {
                IdempotencyKeyModel::create([
                    'user_id' => $user->id,
                    'key_hash' => $keyHash,
                    'body_hash' => $bodyHash,
                    'response_status' => $response->getStatusCode(),
                    'response_body' => json_decode($response->getContent() ?: 'null', true),
                ]);
            } catch (QueryException) {
                // A concurrent request with the same key won the race and
                // already stored its response — the unique key_hash rejected
                // this insert. Nothing to do: that response is authoritative.
            }
        }

        return $response;
    }
}
