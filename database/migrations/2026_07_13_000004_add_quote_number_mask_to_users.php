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
            $table->string('quote_number_mask', 40)->nullable()->after('supplier_invoice_number_start');
            $table->unsignedInteger('quote_number_start')->nullable()->after('quote_number_mask');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['quote_number_mask', 'quote_number_start']);
        });
    }
};
