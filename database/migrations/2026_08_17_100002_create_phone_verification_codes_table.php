<?php

declare(strict_types=1);

use App\Modules\Shared\Support\TenantPolicy;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Outstanding SMS one-time codes.
 *
 * code_hash, never the code: it lives for ten minutes, but a leaked table
 * that hands out working codes would let an attacker verify a number they
 * do not hold — and the trial gating hangs off exactly that claim. Hashing
 * costs nothing here because the row is written once and read once.
 *
 * `phone` is stored alongside rather than read from users at confirm time:
 * the code is issued *for a number*, and copying it here is what stops a
 * code requested for one number being spent on another if the profile
 * changes in between.
 *
 * attempts counts down the guesses a single code allows. Six digits is a
 * million possibilities, which sounds ample until you notice an attacker
 * gets to try them at HTTP speed — the cap, not the entropy, is what makes
 * the code safe.
 *
 * Tenant-owned: a code belongs to the account that asked for it, and the
 * account is bound on every request that touches this table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('phone_verification_codes', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('phone', 30);
            $table->string('code_hash');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();
            $table->timestamps();

            // The one query the confirm path runs: newest live code for this
            // account. Also what the cooldown check reads.
            $table->index(['user_id', 'expires_at']);
        });

        TenantPolicy::own('phone_verification_codes');
    }

    public function down(): void
    {
        TenantPolicy::drop('phone_verification_codes');
        Schema::dropIfExists('phone_verification_codes');
    }
};
