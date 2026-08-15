<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Operator-initiated account suspension.
 *
 * A core migration, not an Admin one, even though only the SaaS back office
 * ever sets these columns: the *enforcement* has to live in core (the `auth`
 * middleware and the login action), and core cannot reference a column that
 * only exists in one edition. The column is core; the management surface on
 * top of it is premium, and an OSS build simply never writes to it. Same
 * shape as the edition rule for nullable columns pointing at premium data.
 *
 * Deliberately not `deleted_at`: a suspension is reversible and keeps the
 * account visible to its owner (they are told why), where a soft delete is
 * the account being closed and hides the row from every scope.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->timestamp('suspended_at')->nullable();
            $table->string('suspended_reason')->nullable()
                ->comment('Shown to the account owner on the 403 — keep it something a customer can act on');
        });

        // Suspension is checked on every authenticated request; the partial
        // index keeps that a cheap lookup instead of widening the users scan.
        Schema::table('users', function (Blueprint $table): void {
            $table->index('suspended_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropIndex(['suspended_at']);
            $table->dropColumn(['suspended_at', 'suspended_reason']);
        });
    }
};
