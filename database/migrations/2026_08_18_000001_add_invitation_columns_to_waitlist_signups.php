<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Turns the waitlist from a list of addresses into a funnel with stages.
 *
 * Still not tenant-owned — no user_id, no HasUserScope, no RLS policy — for
 * the same reason the table never had them: every row here predates the
 * account it might one day become. `registered_at` is stamped rather than
 * joined for so the funnel survives the account being renamed or deleted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('waitlist_signups', function (Blueprint $table): void {
            $table->timestamp('invited_at')->nullable();

            // The hash, never the token — the same discipline as a password
            // reset. A leaked database must not hand anyone a way into a
            // closed registration.
            $table->string('invitation_token_hash', 64)->nullable()->unique();
            $table->timestamp('invitation_expires_at')->nullable();

            // Resends are a normal part of running a beta; the count is what
            // tells "never invited" apart from "invited four times and still
            // silent" when deciding who to chase.
            $table->unsignedSmallInteger('invite_count')->default(0);

            $table->timestamp('registered_at')->nullable();
        });

        // The two queries this table now answers on every admin page load:
        // "who is waiting" and "who has gone quiet since being invited".
        Schema::table('waitlist_signups', function (Blueprint $table): void {
            $table->index(['invited_at', 'registered_at']);
        });
    }

    public function down(): void
    {
        Schema::table('waitlist_signups', function (Blueprint $table): void {
            $table->dropIndex(['invited_at', 'registered_at']);
            $table->dropUnique(['invitation_token_hash']);
            $table->dropColumn([
                'invited_at',
                'invitation_token_hash',
                'invitation_expires_at',
                'invite_count',
                'registered_at',
            ]);
        });
    }
};
