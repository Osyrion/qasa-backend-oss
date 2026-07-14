<?php

declare(strict_types=1);

namespace App\Modules\Shared\Presentation\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Deeper than the framework's default /up: actually exercises the DB
 * connection, queue backlog, mail transport, and storage disk instead of
 * just confirming the app booted. Intended for internal monitoring, not
 * public exposure — route it behind auth or a shared token.
 */
class DeepHealthController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $checks = [
            'db' => $this->checkDatabase(),
            'queue' => $this->checkQueue(),
            'mail' => $this->checkMail(),
            'storage' => $this->checkStorage(),
        ];

        $healthy = collect($checks)->every(fn (array $check): bool => $check['status'] === 'ok');

        return response()->json($checks, $healthy ? 200 : 503);
    }

    /**
     * @return array<string, mixed>
     */
    private function checkDatabase(): array
    {
        try {
            DB::select('select 1');

            return ['status' => 'ok'];
        } catch (Throwable $e) {
            return ['status' => 'error', 'message' => $e->getMessage()];
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function checkQueue(): array
    {
        try {
            $table = (string) config('queue.connections.database.table', 'jobs');

            $size = (int) DB::table($table)->count();

            $oldestCreatedAt = DB::table($table)->min('created_at');

            return [
                'status' => 'ok',
                'size' => $size,
                'oldest_pending_s' => $oldestCreatedAt !== null ? max(0, now()->getTimestamp() - (int) $oldestCreatedAt) : 0,
            ];
        } catch (Throwable $e) {
            return ['status' => 'error', 'message' => $e->getMessage()];
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function checkMail(): array
    {
        try {
            // Resolving the transport exercises config/credentials without
            // actually sending anything.
            Mail::mailer()->getSymfonyTransport();

            return ['status' => 'ok'];
        } catch (Throwable $e) {
            return ['status' => 'error', 'message' => $e->getMessage()];
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function checkStorage(): array
    {
        $path = 'health-check/'.Str::uuid()->toString();

        try {
            Storage::put($path, 'ok');
            Storage::delete($path);

            return ['status' => 'ok'];
        } catch (Throwable $e) {
            return ['status' => 'error', 'message' => $e->getMessage()];
        }
    }
}
