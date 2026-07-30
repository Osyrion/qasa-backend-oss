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
            $table->timestamp('emailed_at')->nullable()
                ->comment('Last time the invoice was queued for email delivery');
            $table->string('emailed_to')->nullable()
                ->comment('Primary recipient of the last email');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn(['emailed_at', 'emailed_to']);
        });
    }
};
