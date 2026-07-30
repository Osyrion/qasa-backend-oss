<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            // Unlike auto_remind_enabled (a client-facing reminder, opt-in),
            // this notifies the owner about their own account — safe to
            // default true.
            $table->boolean('overdue_digest_enabled')->default(true);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('overdue_digest_enabled');
        });
    }
};
