<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->boolean('is_customer')->default(true)->after('is_vat_payer');
            $table->boolean('is_vendor')->default(false)->after('is_customer');

            $table->index(['user_id', 'is_customer']);
            $table->index(['user_id', 'is_vendor']);
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'is_customer']);
            $table->dropIndex(['user_id', 'is_vendor']);
            $table->dropColumn(['is_customer', 'is_vendor']);
        });
    }
};
