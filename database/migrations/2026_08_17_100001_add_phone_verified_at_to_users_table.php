<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `phone` has been a plain profile field since the first migration — typed by
 * whoever felt like it, never checked. This is what turns it into a claim the
 * account has actually proved, which the SaaS edition then gates the free
 * trial on (docs/plans/PHONE_VERIFICATION_TRIAL_ABUSE_PLAN.md).
 *
 * No unique index on `phone`: only a *verified* number is unique, and two
 * accounts may perfectly well have typed the same unverified number into
 * their profile before this existed — a unique constraint would fail the
 * migration on real data. account_for_phone() carries that rule instead,
 * which is also the only way to ask the question at all while `users` is
 * tenant-scoped and nothing is bound. The index here is the plain lookup one
 * that function needs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->timestamp('phone_verified_at')->nullable()->after('phone');
            $table->index('phone');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropIndex(['phone']);
            $table->dropColumn('phone_verified_at');
        });
    }
};
