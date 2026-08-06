<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Laravel's notifications table plus the one column it does not have: the
 * account.
 *
 * `notifiable_id` is the *recipient* — a specific team member. Tenancy in
 * this codebase is keyed on the account *owner* (`app.account_owner_id`),
 * so without a separate `user_id` a team member's notification could not be
 * placed under an account at all: neither HasUserScope nor an RLS policy has
 * anything to compare against. The two columns are the same value only for
 * the owner's own notifications.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->string('notifiable_type');
            $table->uuid('notifiable_id');
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->jsonb('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->index(['notifiable_type', 'notifiable_id']);

            // The account feed, newest first — the list endpoint's only shape.
            $table->index(['user_id', 'created_at']);
        });

        // The unread badge runs on every front-end page load; a partial index
        // keeps it proportional to what is actually unread rather than to
        // everything the account has ever been told.
        DB::statement('CREATE INDEX notifications_unread_idx ON notifications (notifiable_id) WHERE read_at IS NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
