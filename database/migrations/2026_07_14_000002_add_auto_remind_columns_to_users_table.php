<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('auto_remind_enabled')->default(false)->after('invoice_inbox_enabled');
            $table->smallInteger('auto_remind_after_days')->default(3)->after('auto_remind_enabled');
            $table->smallInteger('auto_remind_max_count')->default(3)->after('auto_remind_after_days');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['auto_remind_enabled', 'auto_remind_after_days', 'auto_remind_max_count']);
        });
    }
};
