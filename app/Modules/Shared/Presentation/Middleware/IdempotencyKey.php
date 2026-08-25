<?php

declare(strict_types=1);

namespace App\Modules\Shared\Presentation\Middleware;

use App\Modules\Shared\Domain\Contracts\Actor;
use App\Modules\Shared\Domain\Models\IdempotencyKey as IdempotencyKeyModel;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Opt-in replay protection for critical POSTs: a client retry (network
 * timeout, double-click) that resends the same Idempotency-Key header gets
 * back the original response instead of creating a duplicate record. Without
 * the header, behavior is unchanged — this never affects existing callers.
 *
 * The key is *claimed* before the handler runs, not recorded after it. The
 * unique key_hash is the lock: whoever inserts first owns the request, and a
 * second caller arriving mid-flight is told so (409) rather than being let
 * through to do the work twice. Looking first and inserting afterwards let
 * both requests past the lookup and only failed the loser's insert, long
 * after it had created the duplicate the header exists to prevent.
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

        /** @var Actor|null $user */
        $user = $request->user();

        if ($user === null) {
            return $next($request);
        }

        // Two different ids on purpose. The key namespace is per person: a
        // stored response can carry data specific to whoever made the call,
        // so two team members picking the same key string must not replay
        // each other. The *row* belongs to the account, because
        // idempotency_keys is an account-owned table and its RLS policy
        // compares user_id against the bound account — writing the person's
        // id there is rejected outright for anyone but the owner.
        $keyHash = hash('sha256', implode('|', [$key, $user->actorId(), $request->method(), $request->path()]));
        $bodyHash = hash('sha256', $request->getContent());

        // A row past its TTL is no longer replayable, but it still occupies
        // the unique key_hash — clear it out so the claim below can take the
        // slot instead of colliding with a response nobody may have any more.
        IdempotencyKeyModel::query()
            ->where('key_hash', $keyHash)
            ->where('created_at', '<', now()->subHours(self::TTL_HOURS))
            ->delete();

        if (! $this->claim($user->accountOwnerId(), $keyHash, $bodyHash)) {
            return $this->replay($keyHash, $bodyHash);
        }

        try {
            $response = $next($request);
        } catch (Throwable $e) {
            // Nothing was answered, so nothing is worth replaying — release
            // the slot rather than wedging the key until its TTL expires.
            $this->release($keyHash);

            throw $e;
        }

        if ($response->getStatusCode() >= 500) {
            $this->release($keyHash);

            return $response;
        }

        IdempotencyKeyModel::query()->where('key_hash', $keyHash)->update([
            'response_status' => $response->getStatusCode(),
            'response_body' => json_encode($this->decodeJson($response)),
        ]);

        return $response;
    }

    /**
     * Take ownership of this key for $accountId. False when somebody else
     * already holds it.
     *
     * ON CONFLICT DO NOTHING rather than catching the unique violation:
     * Postgres aborts the entire surrounding transaction on a failed
     * statement, so a caught exception would leave the connection unusable
     * for anything that wrapped the request — the test suite's per-test
     * transaction being the obvious one, but any future outer transaction
     * just as much.
     */
    private function claim(string $accountId, string $keyHash, string $bodyHash): bool
    {
        return IdempotencyKeyModel::query()->insertOrIgnore([
            'id' => (string) Str::uuid(),
            'user_id' => $accountId,
            'key_hash' => $keyHash,
            'body_hash' => $bodyHash,
            'response_status' => null,
            'response_body' => null,
            'created_at' => now(),
        ]) === 1;
    }

    private function release(string $keyHash): void
    {
        IdempotencyKeyModel::query()->where('key_hash', $keyHash)->delete();
    }

    /**
     * Answer a caller whose key is already claimed: the stored response, a
     * conflict when the body differs, or 409 while the original is still
     * running.
     */
    private function replay(string $keyHash, string $bodyHash): Response
    {
        $existing = IdempotencyKeyModel::query()->where('key_hash', $keyHash)->first();

        if ($existing === null) {
            // Lost the claim to a request that has since released it (5xx or
            // a thrown exception). Nothing to replay and nothing in flight —
            // tell the caller to retry rather than silently doing the work.
            return response()->json(['message' => __('shared.idempotency_key_in_flight')], 409);
        }

        if ($existing->body_hash !== $bodyHash) {
            return response()->json(['message' => __('shared.idempotency_key_conflict')], 422);
        }

        if ($existing->response_status === null) {
            return response()->json(['message' => __('shared.idempotency_key_in_flight')], 409);
        }

        return response()->json($existing->response_body, $existing->response_status);
    }

    /**
     * The response body, when it is JSON worth replaying.
     *
     * A file download or a PDF has nothing meaningful to store; keeping the
     * status without a body is the honest record, and beats replaying `null`
     * as though it were the original payload.
     *
     * @return array<mixed>|null
     */
    private function decodeJson(Response $response): ?array
    {
        $content = $response->getContent();

        if ($content === false || $content === '') {
            return null;
        }

        $decoded = json_decode($content, true);

        return is_array($decoded) ? $decoded : null;
    }
}
