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
            $table->foreignUuid('settled_invoice_id')
                ->nullable()
                ->after('related_invoice_id')
                ->constrained('invoices')
                ->nullOnDelete()
                ->comment('Ordinary invoice created when this proforma was settled');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropConstrainedForeignId('settled_invoice_id');
        });
    }
};
