<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoice_inbox_items', function (Blueprint $table): void {
            $table->string('suggestions_source')->nullable()->after('suggestions')
                ->comment('regex|ai|ai_byok — which InvoiceFieldExtractor produced "suggestions"');
        });
    }

    public function down(): void
    {
        Schema::table('invoice_inbox_items', function (Blueprint $table): void {
            $table->dropColumn('suggestions_source');
        });
    }
};
