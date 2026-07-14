<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('idempotency_keys', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();

            // Hash of the caller-supplied key + user + route — identifies a
            // retried request regardless of body.
            $table->string('key_hash', 64)->unique();

            // Hash of the request body — a second request reusing the same
            // Idempotency-Key with a different body is a caller error, not a
            // retry, and gets rejected rather than silently replayed.
            $table->string('body_hash', 64);

            $table->unsignedSmallInteger('response_status');
            $table->json('response_body')->nullable();

            $table->timestamp('created_at')->useCurrent();

            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('idempotency_keys');
    }
};
