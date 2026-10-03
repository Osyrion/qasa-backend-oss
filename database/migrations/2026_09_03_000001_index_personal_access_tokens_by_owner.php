<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * personal_access_tokens is reached through its owner, never by a user_id of
 * its own, and Sanctum's only index over that is (tokenable_type,
 * tokenable_id) — a composite whose leading column is the morph string.
 *
 * Shared\PurgeRequestMetadataAction walks accounts one at a time and has no
 * business knowing how the account model spells its morph alias, so it filters
 * on tokenable_id alone. Without this index that filter is unindexable and the
 * purge scans the whole table once per account.
 *
 * Deliberately not replacing the composite: Sanctum's own $user->tokens()
 * queries both columns and the morph string is what makes it selective there.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table): void {
            $table->index('tokenable_id');
        });
    }

    public function down(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table): void {
            $table->dropIndex(['tokenable_id']);
        });
    }
};
