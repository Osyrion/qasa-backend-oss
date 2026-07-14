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
            $table->boolean('auto_remind_enabled')->default(false);
            $table->unsignedSmallInteger('auto_remind_max')->default(3);
            $table->unsignedSmallInteger('auto_remind_interval_days')->default(7);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['auto_remind_enabled', 'auto_remind_max', 'auto_remind_interval_days']);
        });
    }
};
