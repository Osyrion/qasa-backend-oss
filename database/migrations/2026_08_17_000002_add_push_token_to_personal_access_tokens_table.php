<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A push token belongs to a device, and a device is already a `session`-type
 * personal_access_tokens row (SessionController's "my devices") — piggybacking
 * here means a revoked/expired session silently stops receiving pushes too,
 * with no separate device table to keep in sync or clean up.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table): void {
            $table->string('push_token')->nullable()->after('user_agent');
            $table->string('push_platform', 10)->nullable()->after('push_token')->comment('ios|android');
        });
    }

    public function down(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table): void {
            $table->dropColumn(['push_token', 'push_platform']);
        });
    }
};
