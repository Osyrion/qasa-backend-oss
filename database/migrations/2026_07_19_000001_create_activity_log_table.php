<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('activity_log', function (Blueprint $table) {
            $table->uuid('id')->primary();

            // Tenant scope — matches HasUserScope's convention on every
            // other table (team members share the owner's account id).
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();

            // Who actually performed the action; null for system-triggered
            // events (scheduled commands, queued jobs) where there is no
            // authenticated actor.
            $table->foreignUuid('actor_id')->nullable()->constrained('users')->nullOnDelete();

            $table->uuidMorphs('subject');
            $table->string('event', 100)->comment('e.g. invoice.status_changed');
            $table->json('changes')->nullable()->comment('old/new values, shape varies per event');

            $table->timestamp('created_at')->useCurrent();

            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_log');
    }
};
