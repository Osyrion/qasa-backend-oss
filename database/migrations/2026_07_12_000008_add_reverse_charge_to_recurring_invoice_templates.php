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
            // Intent only — the actual mode is re-resolved from the current
            // client at each generation, never stored here (see
            // GenerateInvoiceFromTemplateAction).
            $table->boolean('reverse_charge')->default(false)->after('discount_percent');
        });
    }

    public function down(): void
    {
        Schema::table('recurring_invoice_templates', function (Blueprint $table) {
            $table->dropColumn('reverse_charge');
        });
    }
};
