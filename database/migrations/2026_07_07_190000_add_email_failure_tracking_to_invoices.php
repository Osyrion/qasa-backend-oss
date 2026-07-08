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
            $table->timestamp('email_failed_at')->nullable()
                ->comment('Set when the queued email job permanently failed; cleared on the next send');
            $table->json('emailed_cc')->nullable()
                ->comment('CC recipients of the last email');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn(['email_failed_at', 'emailed_cc']);
        });
    }
};
