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
            $table->foreignUuid('recurring_template_id')
                ->nullable()
                ->constrained('recurring_invoice_templates')
                ->nullOnDelete();
            $table->text('note_above')->nullable()->after('note')
                ->comment('Printed above the items table; note prints below');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropConstrainedForeignId('recurring_template_id');
            $table->dropColumn('note_above');
        });
    }
};
