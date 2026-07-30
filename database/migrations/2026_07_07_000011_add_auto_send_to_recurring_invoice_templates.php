<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('recurring_invoice_templates', function (Blueprint $table) {
            $table->boolean('auto_send')->default(false)->after('tax_date_mode')
                ->comment('Issue (draft→sent) and email generated invoices automatically');
        });
    }

    public function down(): void
    {
        Schema::table('recurring_invoice_templates', function (Blueprint $table) {
            $table->dropColumn('auto_send');
        });
    }
};
