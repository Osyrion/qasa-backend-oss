<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->string('public_token', 64)->nullable()->unique()->after('reminder_count');
            $table->timestamp('public_first_viewed_at')->nullable()->after('public_token');
            $table->unsignedInteger('public_view_count')->default(0)->after('public_first_viewed_at');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn(['public_token', 'public_first_viewed_at', 'public_view_count']);
        });
    }
};
