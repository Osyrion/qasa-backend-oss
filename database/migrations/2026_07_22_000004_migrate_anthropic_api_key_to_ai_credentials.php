<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Moves users.anthropic_api_key into ai_credentials — a byte-for-byte
     * copy of the encrypted ciphertext (both columns use the same
     * 'encrypted' cast against the same APP_KEY, so no decrypt/re-encrypt
     * round-trip is needed to move it).
     */
    public function up(): void
    {
        DB::table('users')
            ->whereNotNull('anthropic_api_key')
            ->orderBy('id')
            ->select('id', 'anthropic_api_key', 'created_at')
            ->chunkById(100, function ($users): void {
                $now = now();

                $rows = $users->map(fn (object $user): array => [
                    'id' => (string) Str::uuid(),
                    'user_id' => $user->id,
                    'provider' => 'anthropic',
                    'api_key' => $user->anthropic_api_key,
                    'created_at' => $user->created_at ?? $now,
                    'updated_at' => $now,
                ])->all();

                if ($rows !== []) {
                    DB::table('ai_credentials')->insert($rows);
                }
            });
    }

    public function down(): void
    {
        // Irreversible: the next migration drops users.anthropic_api_key,
        // and re-splitting ai_credentials back onto it would no longer be
        // able to tell a migrated row from one created via the new API.
    }
};
