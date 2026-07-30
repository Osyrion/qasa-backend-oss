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
            $table->string('invoice_number_mask', 40)->nullable()->after('invoice_prefix');
            $table->unsignedInteger('invoice_number_start')->nullable()->after('invoice_number_mask');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'invoice_number_mask',
                'invoice_number_start',
            ]);
        });
    }
};
