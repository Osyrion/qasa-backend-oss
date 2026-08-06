<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Distinguishes a login token (`session` — created by LoginAction and every
 * other path that signs a user in) from a manually-created integration
 * token (`api` — PersonalAccessTokenController::store). Sanctum's stock
 * schema has no such column and no way to tell them apart other than the
 * `abilities === ['*']` convention, which SessionController/
 * PersonalAccessTokenController would otherwise have to duplicate. Nullable
 * because existing rows predate this column and are neither.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table): void {
            $table->string('type', 10)->nullable()->after('name')->comment('session|api');
            $table->string('ip_address', 45)->nullable()->after('abilities');
            $table->string('user_agent')->nullable()->after('ip_address');
        });
    }

    public function down(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table): void {
            $table->dropColumn(['type', 'ip_address', 'user_agent']);
        });
    }
};
